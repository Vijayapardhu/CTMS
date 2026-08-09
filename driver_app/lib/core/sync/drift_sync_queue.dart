import 'dart:convert';
import 'dart:math';

import 'package:drift/drift.dart';

import '../services/logger_service.dart';
import 'sync_database.dart';
import 'sync_queue.dart';

/// The Slice 0 [SyncQueue] interface, backed by the Drift database.
///
/// Everything that survives a process kill lives here and nowhere else. The
/// two invariants the rest of the app relies on:
///
/// * an [QueuedAction.idempotencyKey] is minted exactly once, inside [enqueue],
///   and is never regenerated — not on retry, not on restart;
/// * [sequence] is allocated per trip under the same transaction that inserts
///   the row, so two actions enqueued from different screens cannot collide.
class DriftSyncQueue implements SyncQueue {
  DriftSyncQueue(this._db, this._logger, {Random? random})
      : _random = random ?? Random.secure();

  final SyncDatabase _db;
  final LoggerService _logger;
  final Random _random;

  /// Mints the key for an action about to be queued.
  ///
  /// Callers pass this to [QueuedAction] rather than inventing their own, so
  /// there is one place where a key comes into existence.
  String newIdempotencyKey() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));

    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  @override
  Future<void> enqueue(QueuedAction action) async {
    await _db.transaction(() async {
      // Allocated inside the transaction: two writers racing would otherwise
      // read the same maximum and both claim it.
      final sequence = action.sequence > 0
          ? action.sequence
          : await _nextSequence(action.tripId);

      await _db.into(_db.queuedActions).insert(
            QueuedActionsCompanion.insert(
              id: action.id,
              kind: action.kind,
              payload: jsonEncode(action.payload),
              idempotencyKey: action.idempotencyKey,
              sequence: sequence,
              createdAt: action.createdAt,
              tripId: Value(action.tripId),
              isCompound: Value(action.isCompound),
            ),
          );
    });
  }

  Future<int> _nextSequence(String? tripId) async {
    final rows = await (_db.selectOnly(_db.queuedActions)
          ..addColumns([_db.queuedActions.sequence.max()])
          ..where(tripId == null
              ? _db.queuedActions.tripId.isNull()
              : _db.queuedActions.tripId.equals(tripId)))
        .get();

    return (rows.firstOrNull?.read(_db.queuedActions.sequence.max()) ?? 0) + 1;
  }

  @override
  Future<List<QueuedAction>> pending({String? tripId}) async {
    final query = _db.select(_db.queuedActions)
      ..where((t) => t.lastFailure.isNull())
      ..orderBy([
        // Oldest first, and within a trip strictly by sequence. Replaying a
        // boarding before the arrival that precedes it makes the server refuse
        // it for a reason that has nothing to do with what happened.
        (t) => OrderingTerm(expression: t.createdAt),
        (t) => OrderingTerm(expression: t.sequence),
      ]);

    if (tripId != null) query.where((t) => t.tripId.equals(tripId));

    return (await query.get()).map(_toAction).toList(growable: false);
  }

  @override
  Future<List<QueuedAction>> failed() async {
    final rows = await (_db.select(_db.queuedActions)
          ..where((t) => t.lastFailure.isNotNull())
          ..orderBy([(t) => OrderingTerm(expression: t.createdAt)]))
        .get();

    return rows.map(_toAction).toList(growable: false);
  }

  @override
  Future<void> resolve(String id, ReplayOutcome outcome, {String? message}) async {
    switch (outcome) {
      // Both leave the server holding exactly one copy, which is the only
      // thing that matters. A duplicate is the idempotency key doing its job
      // and is never shown to the driver as a failure.
      case ReplayOutcome.accepted:
      case ReplayOutcome.duplicate:
        await (_db.delete(_db.queuedActions)..where((t) => t.id.equals(id)))
            .go();

      case ReplayOutcome.refused:
        await (_db.update(_db.queuedActions)..where((t) => t.id.equals(id)))
            .write(QueuedActionsCompanion(
          lastFailure: Value(message ?? 'The server refused this action.'),
        ));

      case ReplayOutcome.retry:
        await _db.customUpdate(
          'UPDATE queued_actions SET attempts = attempts + 1 WHERE id = ?',
          variables: [Variable<String>(id)],
          updates: {_db.queuedActions},
        );

      case ReplayOutcome.tripClosed:
        // Handled by purgeTrip: one row is never the whole story here.
        _logger.warn('Trip closed under the queue', context: {'action': id});
    }
  }

  @override
  Future<void> purgeTrip(String tripId) async {
    await (_db.delete(_db.queuedActions)..where((t) => t.tripId.equals(tripId)))
        .go();
  }

  /// A live count of everything still owed, failures included.
  ///
  /// A stream rather than a poll: the banner is driven by the same table the
  /// engine is draining, so it cannot drift out of step with it.
  @override
  Stream<int> get pendingCount {
    final query = _db.selectOnly(_db.queuedActions)
      ..addColumns([_db.queuedActions.id.count()]);

    return query
        .watch()
        .map((rows) =>
            rows.firstOrNull?.read(_db.queuedActions.id.count()) ?? 0);
  }

  /// Everything still owed to the server, failures included.
  Future<int> count() async {
    final rows = await (_db.selectOnly(_db.queuedActions)
          ..addColumns([_db.queuedActions.id.count()]))
        .get();

    return rows.firstOrNull?.read(_db.queuedActions.id.count()) ?? 0;
  }

  /// Puts a failed action back in line, for the driver's explicit retry.
  Future<void> retryFailed() async {
    await _db.update(_db.queuedActions).write(
          const QueuedActionsCompanion(lastFailure: Value(null)),
        );
  }

  QueuedAction _toAction(QueuedActionRow row) {
    final decoded = jsonDecode(row.payload);

    return QueuedAction(
      id: row.id,
      kind: row.kind,
      payload: decoded is Map<String, Object?> ? decoded : const {},
      idempotencyKey: row.idempotencyKey,
      sequence: row.sequence,
      createdAt: row.createdAt,
      tripId: row.tripId,
      attempts: row.attempts,
      lastFailure: row.lastFailure,
      isCompound: row.isCompound,
    );
  }
}
