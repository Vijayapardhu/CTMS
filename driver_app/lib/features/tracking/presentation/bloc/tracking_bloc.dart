import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../data/tracking_repository.dart';
import '../../domain/live_trip.dart';

sealed class TrackingEvent extends Equatable {
  const TrackingEvent();

  @override
  List<Object?> get props => const [];
}

/// The map became visible for a running trip.
final class TrackingStarted extends TrackingEvent {
  const TrackingStarted({required this.tripId, required this.routeId});

  final String tripId;
  final String routeId;

  @override
  List<Object?> get props => [tripId, routeId];
}

/// The map went away, or the trip stopped running.
final class TrackingStopped extends TrackingEvent {
  const TrackingStopped();
}

/// One poll — from the timer, or from the driver pulling to refresh.
final class TrackingPolled extends TrackingEvent {
  const TrackingPolled();
}

/// What the map has to draw.
///
/// Deliberately one state carrying nullable parts rather than a state per
/// combination. A driver can perfectly well have stops but no position, or a
/// position but no estimate, and modelling every pairing as its own class would
/// produce a dozen states that all render nearly the same screen.
final class TrackingState extends Equatable {
  const TrackingState({
    this.loading = false,
    this.trip,
    this.eta,
    this.stops = const [],
    this.etaFailed = false,
    this.routeFailed = false,
    this.failure,
  });

  /// The first read is in flight and there is nothing to draw yet.
  final bool loading;

  final LiveTrip? trip;
  final Eta? eta;

  /// Stop geography. Empty when the route read failed — see [routeFailed].
  final List<RouteStop> stops;

  /// The live state arrived; the estimate did not.
  final bool etaFailed;

  /// The stops could not be read, so there is no route to draw. Distinct from
  /// having no position: one is a missing line, the other a missing bus.
  final bool routeFailed;

  /// Why the last poll did not land. Present alongside whatever is still held,
  /// because a trip already on screen is not discarded when a poll fails.
  final ApiFailure? failure;

  /// Nothing has arrived at all yet.
  bool get isEmpty => trip == null;

  /// The server has never had a position for this trip.
  bool get hasPosition => trip?.position != null;

  /// The server has one but will not stand behind it.
  bool get isStale => trip?.position?.isStale ?? false;

  /// The last poll failed and what is on screen is older than it looks.
  bool get isStalled => failure != null;

  TrackingState copyWith({
    bool? loading,
    LiveTrip? trip,
    Eta? eta,
    List<RouteStop>? stops,
    bool? etaFailed,
    bool? routeFailed,
    ApiFailure? failure,
    bool clearFailure = false,
    bool clearEta = false,
  }) {
    return TrackingState(
      loading: loading ?? this.loading,
      trip: trip ?? this.trip,
      eta: clearEta ? null : (eta ?? this.eta),
      stops: stops ?? this.stops,
      etaFailed: etaFailed ?? this.etaFailed,
      routeFailed: routeFailed ?? this.routeFailed,
      failure: clearFailure ? null : (failure ?? this.failure),
    );
  }

  @override
  List<Object?> get props =>
      [loading, trip, eta, stops, etaFailed, routeFailed, failure];
}

/// Drives the live map.
///
/// Polls `/trips/{id}/live` on the cadence R1 specifies, and only while the map
/// is actually on screen — a backgrounded map polling every ten seconds spends
/// a driver's battery and data on pixels nobody is looking at. Position posting
/// is a separate concern and keeps running regardless; that is M3's job.
class TrackingBloc extends Bloc<TrackingEvent, TrackingState> {
  TrackingBloc({
    required TrackingRepository repository,
    Duration interval = const Duration(seconds: 10),
  })  : _repository = repository,
        _interval = interval,
        super(const TrackingState()) {
    on<TrackingStarted>(_onStarted);
    on<TrackingStopped>(_onStopped);
    on<TrackingPolled>(_onPolled);
  }

  final TrackingRepository _repository;
  final Duration _interval;

  Timer? _timer;
  String? _tripId;

  Future<void> _onStarted(
    TrackingStarted event,
    Emitter<TrackingState> emit,
  ) async {
    if (_tripId == event.tripId && _timer != null) return;

    _timer?.cancel();
    _tripId = event.tripId;

    emit(TrackingState(loading: state.trip == null, trip: state.trip));

    // Where the stops are, once. A failure here costs the route line and the
    // stop markers but not the bus, so it is recorded and the poll continues.
    try {
      emit(state.copyWith(
        stops: await _repository.stopsFor(event.routeId),
        routeFailed: false,
      ));
    } on ApiFailure {
      emit(state.copyWith(routeFailed: true));
    }

    _timer = Timer.periodic(_interval, (_) => add(const TrackingPolled()));
    add(const TrackingPolled());
  }

  Future<void> _onPolled(
    TrackingPolled event,
    Emitter<TrackingState> emit,
  ) async {
    final tripId = _tripId;
    if (tripId == null) return;

    try {
      final snapshot = await _repository.poll(tripId);

      emit(state.copyWith(
        loading: false,
        trip: snapshot.trip,
        eta: snapshot.eta,
        clearEta: snapshot.eta == null,
        etaFailed: snapshot.etaFailed,
        clearFailure: true,
      ));
    } on ApiFailure catch (e) {
      // Whatever was last drawn stays drawn. The screen marks it as not
      // current rather than blanking a map the driver is navigating by.
      emit(state.copyWith(loading: false, failure: e));
    }
  }

  Future<void> _onStopped(
    TrackingStopped event,
    Emitter<TrackingState> emit,
  ) async {
    _timer?.cancel();
    _timer = null;
    _tripId = null;
    emit(const TrackingState());
  }

  @override
  Future<void> close() {
    _timer?.cancel();
    return super.close();
  }
}
