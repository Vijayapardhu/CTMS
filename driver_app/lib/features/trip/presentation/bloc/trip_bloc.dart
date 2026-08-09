import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/services/logger_service.dart';
import '../../data/trip_repository.dart';
import '../../domain/trip.dart';
import '../../domain/trip_state.dart';

sealed class TripEvent extends Equatable {
  const TripEvent();

  @override
  List<Object?> get props => const [];
}

/// Read the day's trip. The only event Slice 3 has — everything else on this
/// screen is a later slice's mutation.
final class TripRequested extends TripEvent {
  const TripRequested();
}

/// Pull-to-refresh, app resume, or the retry on the error card.
final class TripRefreshed extends TripEvent {
  const TripRefreshed();
}

/// M1.
///
/// App-scoped, per the bloc mapping in Phase 4: a driver switching to the map
/// mid-trip must come back to the same trip rather than a fresh load.
class TripBloc extends Bloc<TripEvent, TripState> {
  TripBloc({required TripRepository repository, required LoggerService logger})
      : _repository = repository,
        _logger = logger,
        super(const TripLoading()) {
    on<TripRequested>(_onRequested);
    on<TripRefreshed>(_onRequested);
  }

  final TripRepository _repository;
  final LoggerService _logger;

  Future<void> _onRequested(TripEvent event, Emitter<TripState> emit) async {
    // No loading state on a refresh. The trip already on screen stays there
    // while the request runs; replacing it with a skeleton would blank the
    // day's work every time the driver pulled down.
    if (state.trip == null && state is! TripUnavailable) {
      emit(const TripLoading());
    }

    try {
      emit(_resolve(await _repository.load()));
    } on ApiFailure catch (e) {
      emit(_onFailure(e));
    }
  }

  /// Which M1 state the server's answer implies.
  TripState _resolve(TripSnapshot snapshot) {
    final trip = snapshot.trip;

    if (trip == null) return const TripNone();

    return switch (trip.status) {
      TripStatus.running => TripRunning(value: trip),
      TripStatus.completed || TripStatus.cancelled => TripClosed(value: trip),
      TripStatus.scheduled || TripStatus.unknown => _scheduled(trip, snapshot),
    };
  }

  TripState _scheduled(Trip trip, TripSnapshot snapshot) {
    // No readiness answer means no clearance. Defaulting the other way would
    // present an uncleared bus as ready to carry children.
    final clearance = snapshot.readiness ??
        const ServiceReadiness(cleared: false, reasons: []);

    return clearance.cleared
        ? TripReady(value: trip, clearance: clearance)
        : TripBlocked(value: trip, clearance: clearance);
  }

  /// A failure never destroys a trip that was already read.
  TripState _onFailure(ApiFailure failure) {
    // 401 is not this bloc's business. The API client refreshes once and the
    // session machine ends the session if that fails; duplicating it here
    // would give a driver two competing answers about being signed in.
    if (failure is AuthFailure) return state;

    if (failure is ForbiddenFailure) {
      // Trips are driver-scoped server-side, so this should be unreachable.
      // It is worth a line in the log precisely because it means an
      // assumption is wrong.
      _logger.warn('Trips refused for the signed-in driver');
    }

    final held = state.trip;

    if (held == null) return TripUnavailable(failure);

    return switch (state) {
      TripBlocked(:final value, :final clearance) =>
        TripBlocked(value: value, clearance: clearance, stale: true, failure: failure),
      TripReady(:final value, :final clearance) =>
        TripReady(value: value, clearance: clearance, stale: true, failure: failure),
      TripWaiting(:final value, :final clearance, :final opensAt) => TripWaiting(
          value: value,
          clearance: clearance,
          opensAt: opensAt,
          stale: true,
          failure: failure,
        ),
      TripRunning(:final value) =>
        TripRunning(value: value, stale: true, failure: failure),
      TripClosed(:final value) =>
        TripClosed(value: value, stale: true, failure: failure),
      // Unreachable: these three hold no trip, and `held` is non-null here.
      TripLoading() || TripNone() || TripUnavailable() => state,
    };
  }
}
