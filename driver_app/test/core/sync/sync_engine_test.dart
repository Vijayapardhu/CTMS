import 'package:ctms_driver/core/api/api_failure.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/sync/drift_sync_queue.dart';
import 'package:ctms_driver/core/sync/sync_database.dart';
import 'package:ctms_driver/core/sync/sync_engine.dart';
import 'package:ctms_driver/core/sync/sync_queue.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_doubles.dart';

/// A connectivity service the test drives directly.
class _Reach implements ConnectivityService {
  @override
  Reachability current = Reachability.online;

  @override
  Stream<Reachability> get changes => const Stream.empty();

  @override
  void recordFailure() {}

  @override
  void recordSuccess() {}
}

/// How the engine reads the server's answers.
///
/// This is the file that decides whether a driver's work is kept or thrown
/// away, so every branch of the table in `04-state-machines.md` M3 is here.
void main() {
  late SyncDatabase db;
  late DriftSyncQueue queue;
  late _Reach reach;

  setUp(() {
    db = SyncDatabase.forTesting(NativeDatabase.memory());
    queue = DriftSyncQueue(db, SilentLogger());
    reach = _Reach();
  });

  tearDown(() => db.close());

  SyncEngine engineThat(ActionSender sender, {List<String> keysSeen = const []}) {
    return SyncEngine(
      queue: queue,
      connectivity: reach,
      logger: SilentLogger(),
      senders: {SyncKinds.position: sender},
      // No real waiting: the throttle has its own test.
      gap: Duration.zero,
      rateLimitPause: Duration.zero,
    );
  }

  Future<void> enqueueFix({String id = 'f1', String? trip = 'trip-1'}) {
    return queue.enqueue(QueuedAction(
      id: id,
      kind: SyncKinds.position,
      payload: const {'latitude': 12.9, 'longitude': 77.5},
      idempotencyKey: 'key-$id',
      sequence: 0,
      createdAt: DateTime.utc(2026, 8, 9, 8),
      tripId: trip,
    ));
  }

  test('a stored position leaves the queue', () async {
    await enqueueFix();
    final engine = engineThat((_) async => {'data': {'id': 'loc-1'}});

    final report = await engine.drain();

    expect(report.sent, 1);
    expect(await queue.pending(), isEmpty);
  });

  test('an absorbed duplicate is success, never a failure', () async {
    await enqueueFix();
    // What `POST /positions` actually answers for a key it has already seen:
    // 200, with a null payload.
    final engine = engineThat((_) async => {'data': null});

    final report = await engine.drain();

    expect(report.duplicates, 1);
    expect(report.refused, 0);
    expect(await queue.failed(), isEmpty);
    expect(await queue.pending(), isEmpty);
  });

  test('the same key is sent on every attempt', () async {
    await enqueueFix();
    final keys = <String>[];

    var calls = 0;
    final engine = engineThat((action) async {
      keys.add(action.idempotencyKey);
      if (calls++ == 0) throw const ServerFailure();
      return {'data': {'id': 'loc-1'}};
    });

    await engine.drain();
    await engine.drain();

    expect(keys, hasLength(2));
    expect(
      keys.first,
      keys.last,
      reason: 'a new key per attempt is how one position becomes two',
    );
  });

  test('an implausible reading is dropped, not kept as a failure', () async {
    await enqueueFix();
    final engine = engineThat(
      (_) async => throw const ConflictFailure(
        'Position rejected: implausible speed',
        context: {'reason': 'implausible speed'},
      ),
    );

    final report = await engine.drain();

    expect(report.declinedPositions, 1);
    expect(
      await queue.failed(),
      isEmpty,
      reason: 'the server is right about its own map, and there is nothing '
          'the driver could do about it anyway',
    );
    expect(await queue.pending(), isEmpty);
  });

  test('outside the service area is counted, not retried forever', () async {
    await enqueueFix();
    final engine = engineThat(
      (_) async => throw const ValidationFailure(
        'The given data was invalid.',
        fieldErrors: {'latitude': ['Outside the service area.']},
      ),
    );

    final report = await engine.drain();

    expect(report.declinedPositions, 1);
    expect(await queue.pending(), isEmpty);
  });

  test('a trip that is no longer running purges its whole buffer', () async {
    await enqueueFix(id: 'f1');
    await enqueueFix(id: 'f2');
    await enqueueFix(id: 'other', trip: 'trip-2');

    final engine = engineThat(
      (_) async => throw const ConflictFailure(
        'Positions are only accepted for a running trip. This trip is COMPLETED.',
      ),
    );

    final report = await engine.drain();

    expect(report.tripClosed, 'trip-1');
    expect(
      (await queue.pending()).map((a) => a.tripId),
      ['trip-2'],
      reason: 'replaying positions at a finished trip argues with the server '
          'on the driver\'s battery',
    );
  });

  test('a rate limit keeps the action and stops the pass', () async {
    await enqueueFix(id: 'f1');
    await enqueueFix(id: 'f2');
    final engine = engineThat((_) async => throw const RateLimitFailure());

    final report = await engine.drain();

    expect(report.sent, 0);
    expect(await queue.pending(), hasLength(2));
  });

  test('a dead socket keeps everything queued', () async {
    await enqueueFix();
    final engine = engineThat((_) async => throw const NetworkFailure());

    await engine.drain();

    expect(await queue.pending(), hasLength(1));
    expect(await queue.failed(), isEmpty);
  });

  test('a 403 is permanent and says so in the server\'s words', () async {
    await enqueueFix();
    final engine = engineThat(
      (_) async => throw const ForbiddenFailure('This trip is not yours.'),
    );

    await engine.drain();

    expect((await queue.failed()).single.lastFailure, 'This trip is not yours.');
  });

  test('going offline mid-pass leaves the rest queued', () async {
    await enqueueFix(id: 'f1');
    await enqueueFix(id: 'f2');
    await enqueueFix(id: 'f3');

    var sent = 0;
    final engine = engineThat((_) async {
      if (++sent == 1) reach.current = Reachability.offline;
      return {'data': {'id': 'loc-$sent'}};
    });

    final report = await engine.drain();

    expect(report.sent, 1);
    expect(await queue.pending(), hasLength(2));
  });

  test('an unknown kind does not block the queue behind it', () async {
    await queue.enqueue(QueuedAction(
      id: 'old',
      kind: 'a-kind-from-a-previous-version',
      payload: const {},
      idempotencyKey: 'key-old',
      sequence: 0,
      createdAt: DateTime.utc(2026, 8, 9, 7),
      tripId: 'trip-1',
    ));
    await enqueueFix(id: 'f1');

    final engine = engineThat((_) async => {'data': {'id': 'loc-1'}});
    final report = await engine.drain();

    expect(report.sent, 1);
    expect(report.refused, 1);
    expect(await queue.pending(), isEmpty);
  });

  test('replay is oldest first', () async {
    await queue.enqueue(QueuedAction(
      id: 'second',
      kind: SyncKinds.position,
      payload: const {},
      idempotencyKey: 'k2',
      sequence: 0,
      createdAt: DateTime.utc(2026, 8, 9, 9),
      tripId: 'trip-1',
    ));
    await queue.enqueue(QueuedAction(
      id: 'first',
      kind: SyncKinds.position,
      payload: const {},
      idempotencyKey: 'k1',
      sequence: 0,
      createdAt: DateTime.utc(2026, 8, 9, 8),
      tripId: 'trip-1',
    ));

    final order = <String>[];
    final engine = engineThat((action) async {
      order.add(action.id);
      return {'data': {'id': action.id}};
    });

    await engine.drain();

    expect(order, ['first', 'second']);
  });

  test('the throttle spaces calls so replay stays under the server\'s limit',
      () async {
    for (var i = 0; i < 3; i++) {
      await enqueueFix(id: 'f$i');
    }

    final engine = SyncEngine(
      queue: queue,
      connectivity: reach,
      logger: SilentLogger(),
      senders: {
        SyncKinds.position: (_) async => {'data': {'id': 'loc'}},
      },
      gap: const Duration(milliseconds: 40),
    );

    final started = DateTime.now();
    await engine.drain();
    final elapsed = DateTime.now().difference(started);

    expect(
      elapsed.inMilliseconds,
      greaterThanOrEqualTo(100),
      reason: 'a burst of 700 buffered fixes must not be fired at a 60/min '
          'endpoint as fast as the queue can read them',
    );
  });
}
