import 'dart:async';

import '../api/api_failure.dart';
import '../connectivity/connectivity_service.dart';
import '../services/logger_service.dart';
import 'drift_sync_queue.dart';
import 'sync_queue.dart';

/// Sends one queued action. Returns the server's envelope.
///
/// Registered per [QueuedAction.kind] so the engine never learns any endpoint:
/// it owns ordering, throttling and classification, and nothing else.
typedef ActionSender = Future<Map<String, dynamic>> Function(QueuedAction action);

/// What a replay pass did.
class SyncReport {
  const SyncReport({
    this.sent = 0,
    this.duplicates = 0,
    this.refused = 0,
    this.declinedPositions = 0,
    this.remaining = 0,
    this.tripClosed,
  });

  final int sent;

  /// Absorbed by the server's idempotency check. Success, not conflict, and
  /// never listed as a failure.
  final int duplicates;

  final int refused;

  /// Readings the server would not store — outside the service area, or
  /// implausible. Dropped, but counted: a driver whose fixes are all being
  /// declined is entitled to know rather than watch a buffer never shrink.
  final int declinedPositions;

  final int remaining;

  /// Set when the server said the trip is no longer running. Everything for
  /// that trip is purged and the caller re-reads it.
  final String? tripClosed;
}

/// Replays the queue.
///
/// Deliberately **not** a second retry system. [ApiClient] already retries a
/// dead socket and a 5xx, refreshes a 401 once, and reports reachability; this
/// layer sees only the outcome and decides whether the action stays queued.
/// Two retry loops would multiply each other's delays.
class SyncEngine {
  SyncEngine({
    required DriftSyncQueue queue,
    required ConnectivityService connectivity,
    required LoggerService logger,
    Map<String, ActionSender> senders = const {},
    Duration gap = const Duration(seconds: 1),
    Duration rateLimitPause = const Duration(seconds: 60),
  })  : _queue = queue,
        _connectivity = connectivity,
        _logger = logger,
        _senders = {...senders},
        _gap = gap,
        _rateLimitPause = rateLimitPause;

  final DriftSyncQueue _queue;
  final ConnectivityService _connectivity;
  final LoggerService _logger;
  final Map<String, ActionSender> _senders;

  /// Minimum spacing between replayed calls.
  ///
  /// M3 caps the stream at 60/min and the server enforces it. A ninety-minute
  /// outage is roughly 700 buffered fixes; at this spacing they drain in the
  /// background over about a quarter of an hour while the driver keeps working,
  /// and nothing is dropped to make that faster.
  final Duration _gap;

  /// How long a 429 parks the whole pass. The server has told us the rate is
  /// too high; sending the next item one second later argues with it.
  final Duration _rateLimitPause;

  bool _running = false;

  /// True while a pass is in flight, for the banner.
  bool get isSyncing => _running;

  void register(String kind, ActionSender sender) => _senders[kind] = sender;

  /// Drains what it can, oldest first.
  ///
  /// Returns early rather than looping forever: connectivity loss stops the
  /// pass and leaves the rest queued, which is exactly what the buffer is for.
  Future<SyncReport> drain({int max = 1000}) async {
    if (_running) return const SyncReport();
    _running = true;

    var sent = 0;
    var duplicates = 0;
    var refused = 0;
    var declined = 0;
    String? closedTrip;

    try {
      final actions = await _queue.pending();

      for (final action in actions.take(max)) {
        if (_connectivity.current == Reachability.offline) break;

        final sender = _senders[action.kind];

        if (sender == null) {
          // A kind with no handler would otherwise sit at the head of the
          // queue and block everything behind it forever.
          _logger.warn('No sender for queued action', context: {'kind': action.kind});
          await _queue.resolve(action.id, ReplayOutcome.refused,
              message: 'This action is no longer supported by the app.');
          refused++;
          continue;
        }

        final outcome = await _attempt(action, sender);

        switch (outcome) {
          case _Attempt(result: ReplayOutcome.accepted):
            sent++;
            await _queue.resolve(action.id, ReplayOutcome.accepted);

          case _Attempt(result: ReplayOutcome.duplicate):
            duplicates++;
            await _queue.resolve(action.id, ReplayOutcome.duplicate);

          case _Attempt(result: ReplayOutcome.refused, :final declined0, :final message):
            // A position the server would not store is dropped, not kept as a
            // failure the driver has to dismiss — it can never succeed, and
            // there is nothing for them to do about it.
            if (declined0) {
              declined++;
              await _queue.resolve(action.id, ReplayOutcome.accepted);
            } else {
              refused++;
              await _queue.resolve(action.id, ReplayOutcome.refused, message: message);
            }

          case _Attempt(result: ReplayOutcome.tripClosed):
            closedTrip = action.tripId;
            if (closedTrip != null) await _queue.purgeTrip(closedTrip);
            // Everything behind this belongs to the same dead trip.
            return SyncReport(
              sent: sent,
              duplicates: duplicates,
              refused: refused,
              declinedPositions: declined,
              remaining: await _queue.count(),
              tripClosed: closedTrip,
            );

          case _Attempt(result: ReplayOutcome.retry, :final pause):
            await _queue.resolve(action.id, ReplayOutcome.retry);
            if (pause) await Future<void>.delayed(_rateLimitPause);
            // Stop the pass rather than hammering past a transient failure.
            // Whatever caused it will very likely refuse the next one too.
            return SyncReport(
              sent: sent,
              duplicates: duplicates,
              refused: refused,
              declinedPositions: declined,
              remaining: await _queue.count(),
            );
        }

        await Future<void>.delayed(_gap);
      }

      return SyncReport(
        sent: sent,
        duplicates: duplicates,
        refused: refused,
        declinedPositions: declined,
        remaining: await _queue.count(),
      );
    } finally {
      _running = false;
    }
  }

  /// Classifies one attempt.
  ///
  /// The mapping is the contract, stated once:
  ///
  /// | Response | Meaning |
  /// |---|---|
  /// | 2xx, `data: null` | already recorded — the idempotency key worked |
  /// | 2xx | stored |
  /// | 409 with a `reason` | the reading was not believable; the server is right |
  /// | 409 without one | the trip is not running; stop and re-read it |
  /// | 422 | shape or service area — permanent, never retried |
  /// | 403 | permanent |
  /// | 401 | the session machine owns this; keep the action |
  /// | 429 | pause, keep the action |
  /// | 5xx, socket | keep the action |
  Future<_Attempt> _attempt(QueuedAction action, ActionSender send) async {
    try {
      final body = await send(action);

      // `POST /trips/{id}/positions` answers 200 with a null payload when the
      // idempotency key has been seen before. That is the replay working, and
      // reporting it as anything else would make a correct offline sync look
      // like a fault.
      return body['data'] == null
          ? const _Attempt(ReplayOutcome.duplicate)
          : const _Attempt(ReplayOutcome.accepted);
    } on ConflictFailure catch (e) {
      if (e.context['reason'] != null) {
        return const _Attempt(ReplayOutcome.refused, declined0: true);
      }

      return const _Attempt(ReplayOutcome.tripClosed);
    } on ValidationFailure catch (e) {
      // Outside the service area arrives here, from the rule on `latitude`.
      return _Attempt(
        ReplayOutcome.refused,
        declined0: action.kind == SyncKinds.position,
        message: e.message,
      );
    } on ForbiddenFailure catch (e) {
      return _Attempt(ReplayOutcome.refused, message: e.message);
    } on RateLimitFailure {
      return const _Attempt(ReplayOutcome.retry, pause: true);
    } on ApiFailure {
      // AuthFailure, ServerFailure, NetworkFailure. All keep the action.
      return const _Attempt(ReplayOutcome.retry);
    }
  }
}

class _Attempt {
  const _Attempt(this.result, {this.declined0 = false, this.pause = false, this.message});

  final ReplayOutcome result;

  /// The refusal was a reading the server declined to store, rather than an
  /// action the driver needs told about.
  final bool declined0;

  final bool pause;
  final String? message;
}

/// The kinds the engine knows how to be given.
abstract final class SyncKinds {
  static const position = 'position';

  /// A head counted at the door. Queued rather than lost when the door
  /// happens to be in a dead spot.
  static const board = 'board';
  static const alight = 'alight';
}
