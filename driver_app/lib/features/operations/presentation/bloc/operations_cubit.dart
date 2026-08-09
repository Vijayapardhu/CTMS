import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/sync/drift_sync_queue.dart';
import '../../../../core/sync/sync_cubit.dart';
import '../../../../core/sync/sync_engine.dart';
import '../../../../core/sync/sync_queue.dart';
import '../../data/operations_api.dart';

/// M4 — the boarding counter, plus the stop and trip actions around it.
///
/// The counter is optimistic and the server is authoritative, which are not in
/// tension: a tap moves the number immediately because a driver counting heads
/// cannot wait for a round trip, and every reply replaces it with the server's
/// own figure. When the two disagree the difference is shown rather than
/// smoothed over — a driver who counted twenty-three and had nineteen land is
/// entitled to know before the office asks.
final class OperationsState extends Equatable {
  const OperationsState({
    this.occupied,
    this.capacity,
    this.pending = 0,
    this.busy = false,
    this.refusal,
    this.rejected = 0,
  });

  /// What is on the bus. The server's figure once one has arrived, moved
  /// optimistically in between.
  final int? occupied;
  final int? capacity;

  /// Taps not yet acknowledged by the server.
  final int pending;

  /// A stop or trip action is in flight. Blocks a second one rather than
  /// letting a double tap arrive twice.
  final bool busy;

  /// The server's last refusal, verbatim. Cleared by the next action.
  final String? refusal;

  /// Boardings the server refused during a replay. Never hidden.
  final int rejected;

  bool get isFull =>
      occupied != null && capacity != null && occupied! >= capacity!;

  OperationsState copyWith({
    int? occupied,
    int? capacity,
    int? pending,
    bool? busy,
    String? refusal,
    int? rejected,
    bool clearRefusal = false,
  }) {
    return OperationsState(
      occupied: occupied ?? this.occupied,
      capacity: capacity ?? this.capacity,
      pending: pending ?? this.pending,
      busy: busy ?? this.busy,
      refusal: clearRefusal ? null : (refusal ?? this.refusal),
      rejected: rejected ?? this.rejected,
    );
  }

  @override
  List<Object?> get props => [
    occupied,
    capacity,
    pending,
    busy,
    refusal,
    rejected,
  ];
}

/// Runs the driver's controls during a trip.
class OperationsCubit extends Cubit<OperationsState> {
  OperationsCubit({
    required OperationsApi api,
    required DriftSyncQueue queue,
    required SyncCubit sync,
  }) : _api = api,
       _queue = queue,
       _sync = sync,
       super(const OperationsState());

  final OperationsApi _api;
  final DriftSyncQueue _queue;
  final SyncCubit _sync;

  String? _tripId;

  /// Takes the server's occupancy from whatever read produced it, so the
  /// counter starts from truth rather than from zero.
  void adopt({required String tripId, int? occupied, int? capacity}) {
    _tripId = tripId;

    // Never overwrite an optimistic count with a poll that predates the taps
    // still in flight; those taps are reconciled by their own replies.
    if (state.pending > 0) {
      emit(state.copyWith(capacity: capacity));
      return;
    }

    emit(state.copyWith(occupied: occupied, capacity: capacity));
  }

  /// One more passenger aboard.
  Future<void> board({String? routeStopId}) =>
      _count(boarding: true, routeStopId: routeStopId);

  /// One fewer.
  Future<void> alight({String? routeStopId}) =>
      _count(boarding: false, routeStopId: routeStopId);

  Future<void> _count({required bool boarding, String? routeStopId}) async {
    final tripId = _tripId;
    if (tripId == null) return;

    final key = _queue.newIdempotencyKey();
    final optimistic = (state.occupied ?? 0) + (boarding ? 1 : -1);

    // Immediately, before anything is sent. The driver is counting people
    // through a door and the number has to keep up with them.
    emit(
      state.copyWith(
        occupied: optimistic < 0 ? 0 : optimistic,
        pending: state.pending + 1,
        clearRefusal: true,
      ),
    );

    try {
      final occupancy = Occupancy.fromEnvelope(
        boarding
            ? await _api.board(
                tripId,
                idempotencyKey: key,
                routeStopId: routeStopId,
              )
            : await _api.alight(
                tripId,
                idempotencyKey: key,
                routeStopId: routeStopId,
              ),
      );

      emit(
        state.copyWith(
          occupied: occupancy.occupied ?? state.occupied,
          capacity: occupancy.capacity ?? state.capacity,
          pending: state.pending - 1,
        ),
      );
    } on NetworkFailure {
      // Queued under the key already minted, so the replay is the same tap
      // rather than a second one. The count stays where the driver put it.
      await _queue.enqueue(
        QueuedAction(
          id: key,
          kind: boarding ? SyncKinds.board : SyncKinds.alight,
          payload: {
            'trip_id': tripId,
            if (routeStopId != null) 'route_stop_id': routeStopId,
          },
          idempotencyKey: key,
          sequence: 0,
          createdAt: DateTime.now().toUtc(),
          tripId: tripId,
        ),
      );

      await _sync.refresh();
      emit(state.copyWith(pending: state.pending - 1));
    } on ApiFailure catch (e) {
      // A refusal — the bus is full, or the trip is not running. The optimistic
      // tap is taken back, because the server did not accept it.
      emit(
        state.copyWith(
          occupied: (state.occupied ?? 0) - (boarding ? 1 : -1),
          pending: state.pending - 1,
          refusal: e.message,
          rejected: state.rejected + 1,
        ),
      );
    }
  }

  /// The bus is at the stop.
  Future<bool> arrive(String stopId) =>
      _act(() => _api.arrive(_tripId!, stopId));

  /// The bus is not stopping here, and the students waiting are told why.
  Future<bool> skip(String stopId, String reason) =>
      _act(() => _api.skip(_tripId!, stopId, reason: reason));

  /// The run is over.
  Future<bool> complete({String? notes}) =>
      _act(() => _api.complete(_tripId!, notes: notes));

  /// Runs one action, keeping the server's refusal if there is one.
  ///
  /// Deliberately not queued. Arriving, skipping and completing change what the
  /// trip *is*, and a driver who is told "done" while the server still thinks
  /// the bus is at the previous stop has been lied to about something they will
  /// act on. These wait for a real answer.
  Future<bool> _act(Future<void> Function() action) async {
    if (_tripId == null || state.busy) return false;

    emit(state.copyWith(busy: true, clearRefusal: true));

    try {
      await action();
      emit(state.copyWith(busy: false));
      return true;
    } on ApiFailure catch (e) {
      emit(state.copyWith(busy: false, refusal: e.message));
      return false;
    }
  }

  /// Puts the counter back to the server's figure after a replay.
  void reconcile({int? occupied, int? capacity}) {
    emit(state.copyWith(occupied: occupied, capacity: capacity, pending: 0));
  }

  void clearRefusal() => emit(state.copyWith(clearRefusal: true));
}
