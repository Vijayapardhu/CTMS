import 'package:ctms_driver/core/sync/drift_sync_queue.dart';
import 'package:ctms_driver/core/sync/sync_database.dart';
import 'package:ctms_driver/core/sync/sync_engine.dart';
import 'package:ctms_driver/core/sync/sync_queue.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_doubles.dart';

/// The queue, against a real in-memory database.
///
/// Every rule in here is one a driver's work depends on: a key that changes on
/// retry double-counts a boarding, and an order that slips replays an arrival
/// before the boarding it followed.
void main() {
  late SyncDatabase db;
  late DriftSyncQueue queue;

  setUp(() {
    db = SyncDatabase.forTesting(NativeDatabase.memory());
    queue = DriftSyncQueue(db, SilentLogger());
  });

  tearDown(() => db.close());

  QueuedAction action({
    String id = 'a1',
    String kind = SyncKinds.position,
    String? tripId = 'trip-1',
    String? key,
    int sequence = 0,
    DateTime? createdAt,
  }) {
    return QueuedAction(
      id: id,
      kind: kind,
      payload: const {'latitude': 12.9, 'longitude': 77.5},
      idempotencyKey: key ?? 'key-$id',
      sequence: sequence,
      createdAt: createdAt ?? DateTime.utc(2026, 8, 9, 8),
      tripId: tripId,
    );
  }

  test('an enqueued action survives to be read back whole', () async {
    await queue.enqueue(action());

    final pending = await queue.pending();

    expect(pending, hasLength(1));
    expect(pending.single.idempotencyKey, 'key-a1');
    expect(pending.single.payload['latitude'], 12.9);
  });

  test('the idempotency key is never rewritten', () async {
    await queue.enqueue(action(key: 'minted-once'));

    // Three failed attempts, exactly as a tunnel would produce.
    for (var i = 0; i < 3; i++) {
      await queue.resolve('a1', ReplayOutcome.retry);
    }

    final pending = await queue.pending();

    expect(
      pending.single.idempotencyKey,
      'minted-once',
      reason: 'a key regenerated on retry turns one action into several',
    );
    expect(pending.single.attempts, 3);
  });

  test('sequence is allocated per trip and preserves order', () async {
    await queue.enqueue(action(id: 'a1'));
    await queue.enqueue(action(id: 'a2'));
    await queue.enqueue(action(id: 'b1', tripId: 'trip-2'));

    final first = await queue.pending(tripId: 'trip-1');
    final second = await queue.pending(tripId: 'trip-2');

    expect(first.map((a) => a.sequence), [1, 2]);
    expect(
      second.single.sequence,
      1,
      reason: 'ordering is per trip, so a second trip starts again at one',
    );
  });

  test('pending comes back oldest first', () async {
    await queue.enqueue(
        action(id: 'late', createdAt: DateTime.utc(2026, 8, 9, 9)));
    await queue.enqueue(
        action(id: 'early', createdAt: DateTime.utc(2026, 8, 9, 8)));

    expect((await queue.pending()).map((a) => a.id), ['early', 'late']);
  });

  test('accepted and duplicate both leave the queue', () async {
    await queue.enqueue(action(id: 'a1'));
    await queue.enqueue(action(id: 'a2'));

    await queue.resolve('a1', ReplayOutcome.accepted);
    await queue.resolve('a2', ReplayOutcome.duplicate);

    expect(await queue.pending(), isEmpty);
    expect(
      await queue.failed(),
      isEmpty,
      reason: 'a duplicate is the idempotency key working, not a failure',
    );
  });

  test('a refusal keeps the server message and leaves pending', () async {
    await queue.enqueue(action());

    await queue.resolve('a1', ReplayOutcome.refused, message: 'Trip is closed.');

    expect(await queue.pending(), isEmpty);
    expect((await queue.failed()).single.lastFailure, 'Trip is closed.');
  });

  test('purging a trip takes only that trip', () async {
    await queue.enqueue(action(id: 'a1'));
    await queue.enqueue(action(id: 'b1', tripId: 'trip-2'));

    await queue.purgeTrip('trip-1');

    expect((await queue.pending()).single.tripId, 'trip-2');
  });

  test('retrying a failure puts it back in line', () async {
    await queue.enqueue(action());
    await queue.resolve('a1', ReplayOutcome.refused, message: 'Nope.');

    await queue.retryFailed();

    expect(await queue.failed(), isEmpty);
    expect(await queue.pending(), hasLength(1));
  });

  test('two keys minted in a row differ', () {
    expect(queue.newIdempotencyKey(), isNot(queue.newIdempotencyKey()));
    expect(queue.newIdempotencyKey(), hasLength(32));
  });
}
