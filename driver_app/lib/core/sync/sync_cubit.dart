import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../connectivity/connectivity_service.dart';
import 'drift_sync_queue.dart';
import 'sync_engine.dart';

/// M6 — the sync queue, as the driver sees it.
sealed class SyncState extends Equatable {
  const SyncState();

  /// How many actions the server has not accepted yet.
  int get waiting => 0;

  @override
  List<Object?> get props => [waiting];
}

/// Nothing owed. No banner.
final class SyncEmpty extends SyncState {
  const SyncEmpty();
}

/// Queued, not yet sent. The one state a driver must never mistake for sent.
final class SyncPending extends SyncState {
  const SyncPending(this.count);

  final int count;

  @override
  int get waiting => count;
}

/// A pass is running.
final class SyncSyncing extends SyncState {
  const SyncSyncing({required this.remaining, this.done = 0});

  final int remaining;
  final int done;

  @override
  int get waiting => remaining;

  @override
  List<Object?> get props => [remaining, done];
}

/// The pass finished and some actions were permanently refused. This is the
/// state most apps drop: a driver whose boarding did not land is told, in the
/// server's own words, rather than left believing it did.
final class SyncPartial extends SyncState {
  const SyncPartial({required this.failed, required this.remaining});

  final int failed;
  final int remaining;

  @override
  int get waiting => remaining;

  @override
  List<Object?> get props => [failed, remaining];
}

/// Owns M6.
///
/// App-scoped and independent of any screen: the queue outlives whatever the
/// driver happened to be looking at when they went into the tunnel.
class SyncCubit extends Cubit<SyncState> {
  SyncCubit({
    required DriftSyncQueue queue,
    required SyncEngine engine,
    required ConnectivityService connectivity,
  })  : _queue = queue,
        _engine = engine,
        super(const SyncEmpty()) {
    // M7's `restored` edge drives M6. This is the subscription slice 2
    // deliberately left unwired, because there was nothing to replay yet.
    _subscription = connectivity.changes.listen((reachability) {
      if (reachability == Reachability.online) unawaited(sync());
    });
  }

  final DriftSyncQueue _queue;
  final SyncEngine _engine;
  late final StreamSubscription<Reachability> _subscription;

  /// Re-reads the queue and reports what is owed, without sending anything.
  Future<void> refresh() async {
    final waiting = await _queue.count();
    final failed = (await _queue.failed()).length;

    // Reading the queue is disk work, and a sign-out can land in the middle of
    // it. The answer is simply stale at that point, not worth an exception.
    if (isClosed) return;

    if (failed > 0) {
      emit(SyncPartial(failed: failed, remaining: waiting));
      return;
    }

    emit(waiting == 0 ? const SyncEmpty() : SyncPending(waiting));
  }

  /// Runs a pass.
  Future<SyncReport> sync() async {
    final before = await _queue.count();
    if (isClosed) return const SyncReport();

    if (before == 0) {
      emit(const SyncEmpty());
      return const SyncReport();
    }

    emit(SyncSyncing(remaining: before));

    final report = await _engine.drain();
    final failed = (await _queue.failed()).length;

    // A pass takes as long as the network does; the session can end inside it.
    if (isClosed) return report;

    if (failed > 0) {
      emit(SyncPartial(failed: failed, remaining: report.remaining));
    } else {
      emit(report.remaining == 0
          ? const SyncEmpty()
          : SyncPending(report.remaining));
    }

    return report;
  }

  /// The driver's explicit "Retry now" from the queue screen.
  Future<void> retryFailed() async {
    await _queue.retryFailed();
    await sync();
  }

  @override
  Future<void> close() async {
    await _subscription.cancel();
    return super.close();
  }
}
