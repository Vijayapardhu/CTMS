import 'dart:async';

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

/// The driver confirmed S2. Only ever raised from `ready`.
final class TripStartRequested extends TripEvent {
  const TripStartRequested();
}

/// The waiting timer elapsed. Puts the button back and lets the server be
/// asked again, which is the only thing that can actually decide.
final class TripStartWindowRechecked extends TripEvent {
  const TripStartWindowRechecked();
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
    on<TripStartRequested>(_onStart);
    on<TripStartWindowRechecked>(_onRecheckWindow);
  }

  final TripRepository _repository;
  final LoggerService _logger;

  /// Re-enables Start after a refusal for being early.
  ///
  /// A fixed cadence rather than a countdown to a known moment: the window
  /// length is server configuration and is exposed nowhere, so the honest
  /// options are to ask again periodically or to make the driver think about
  /// it. This asks.
  static const _windowRecheck = Duration(seconds: 30);

  Timer? _windowTimer;

  @override
  Future<void> close() {
    _windowTimer?.cancel();
    return super.close();
  }

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

  /// M1: `ready → running` on success, `→ waiting` when the trip is early, and
  /// `→ blocked` when a rule refused for a reason the trip must now show.
  Future<void> _onStart(
    TripStartRequested event,
    Emitter<TripState> emit,
  ) async {
    final current = state;
    if (current is! TripReady || current.starting) return;

    _windowTimer?.cancel();

    emit(TripReady(
      value: current.value,
      clearance: current.clearance,
      starting: true,
      stale: current.stale,
      failure: current.failure,
    ));

    try {
      final started = await _repository.start(current.value.id);

      // The server's row, not an assumption that starting produced RUNNING.
      emit(_resolve(TripSnapshot(trip: started)));
    } on ConflictFailure catch (e) {
      emit(_refused(current, e));
    } on ApiFailure catch (e) {
      emit(_startFailed(current, e));
    }
  }

  /// A 409. The server declined, and its wording is the driver's explanation.
  TripState _refused(TripReady current, ConflictFailure failure) {
    // The window refusal is the one that resolves itself, and the one context
    // key that identifies it. Everything else is a rule the driver must see.
    final departure = failure.context['scheduled_departure'];

    if (departure != null) {
      _windowTimer = Timer(
        _windowRecheck,
        () => add(const TripStartWindowRechecked()),
      );

      return TripWaiting(
        value: current.value,
        clearance: current.clearance,
        message: failure.message,
        stale: current.stale,
        failure: current.failure,
      );
    }

    // `reasons[]` when the clearance gate answered, and the single message
    // otherwise. Either way every reason the server gave is carried through —
    // rendering one of several is what this shape exists to prevent.
    final reasons =
        failure.reasons.isNotEmpty ? failure.reasons : [failure.message];

    return TripBlocked(
      value: current.value,
      clearance: ServiceReadiness(
        cleared: false,
        reasons: reasons,
        checkedAt: DateTime.now(),
        hasInspection: current.clearance.hasInspection,
      ),
      stale: current.stale,
      failure: current.failure,
    );
  }

  /// Everything that is not a business refusal. The trip is unchanged, so the
  /// screen stays on `ready` and the button carries the reason.
  TripState _startFailed(TripReady current, ApiFailure failure) {
    // 401 belongs to the session machine, exactly as on a read.
    if (failure is AuthFailure) return current;

    if (failure is ForbiddenFailure) {
      _logger.warn('Start refused for the signed-in driver');
    }

    return TripReady(
      value: current.value,
      clearance: current.clearance,
      refusal: failure.message,
      stale: current.stale,
      failure: current.failure,
    );
  }

  void _onRecheckWindow(
    TripStartWindowRechecked event,
    Emitter<TripState> emit,
  ) {
    final current = state;
    if (current is! TripWaiting) return;

    emit(TripReady(
      value: current.value,
      clearance: current.clearance,
      stale: current.stale,
      failure: current.failure,
    ));
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
      TripWaiting(:final value, :final clearance, :final message) => TripWaiting(
          value: value,
          clearance: clearance,
          message: message,
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
