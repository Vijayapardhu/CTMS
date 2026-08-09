import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/sync/drift_sync_queue.dart';
import 'package:ctms_driver/core/sync/sync_cubit.dart';
import 'package:ctms_driver/core/sync/sync_database.dart';
import 'package:ctms_driver/core/sync/sync_engine.dart';
import 'package:ctms_driver/features/gps/data/location_source.dart';
import 'package:ctms_driver/features/gps/domain/gps_state.dart';
import 'package:ctms_driver/features/gps/presentation/bloc/gps_cubit.dart';
import 'package:ctms_driver/features/trip/data/trip_api.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fake_backend.dart';
import '../../helpers/fake_location.dart';
import '../../helpers/test_doubles.dart';
import '../../helpers/trip_fixtures.dart';

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

/// M3 end to end, against a real queue and the real API client.
///
/// Deliberately not a widget test. Everything here is real asynchrony — a
/// database write, an HTTP round trip, a replay — and the one thing a widget
/// test would add is a pill that has its own file. The slice's promise is
/// "a ninety-minute run with a twenty-minute tunnel loses nothing", and that is
/// a claim about this code, not about a layout.
void main() {
  late SyncDatabase db;
  late DriftSyncQueue queue;
  late FakeBackend backend;
  late FakeLocation location;
  late _Reach reach;
  late SyncCubit sync;
  late GpsCubit gps;

  setUp(() {
    db = SyncDatabase.forTesting(NativeDatabase.memory());
    queue = DriftSyncQueue(db, SilentLogger());
    backend = FakeBackend();
    location = FakeLocation();
    reach = _Reach();

    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      retryDelays: const [],
    )..dio.httpClientAdapter = backend;

    final api = TripApi(client);
    final engine = SyncEngine(
      queue: queue,
      connectivity: reach,
      logger: SilentLogger(),
      senders: {
        SyncKinds.position: (action) => api.recordPosition(
              action.payload['trip_id']! as String,
              Map<String, Object?>.from(action.payload)..remove('trip_id'),
              idempotencyKey: action.idempotencyKey,
            ),
      },
      gap: Duration.zero,
      rateLimitPause: Duration.zero,
    );

    sync = SyncCubit(queue: queue, engine: engine, connectivity: reach);
    gps = GpsCubit(
      source: location,
      queue: queue,
      sync: sync,
      logger: SilentLogger(),
    );
  });

  tearDown(() async {
    await gps.close();
    await sync.close();
    await location.dispose();
    await db.close();
  });

  /// Emits one fix and waits for it to finish being handled.
  Future<void> fix({DateTime? at}) async {
    location.emit(at: at);
    // The handler is a database write plus a replay; a couple of turns of the
    // real event loop is all it takes and all it needs.
    for (var i = 0; i < 20; i++) {
      await Future<void>.delayed(Duration.zero);
    }
  }

  group('the stream follows the trip', () {
    test('starting a running trip acquires', () async {
      await gps.start('trip-1');

      expect(gps.state, isA<GpsAcquiring>());
    });

    test('a fix the server takes shows as live', () async {
      backend.on('/positions', status: 201, body: positionResponse());
      await gps.start('trip-1');

      await fix();

      expect(gps.state, isA<GpsLive>());
      expect(backend.callsTo('/positions'), 1);
      expect(await queue.count(), 0);
    });

    test('stopping leaves the stream idle', () async {
      await gps.start('trip-1');
      await gps.stop();

      expect(gps.state, isA<GpsIdle>());
    });

    test('permission refused is stated, and nothing is streamed', () async {
      location.access = LocationAccess.deniedForever;

      await gps.start('trip-1');

      expect(gps.state, isA<GpsDenied>());
      expect((gps.state as GpsDenied).permanently, isTrue);
      expect(backend.callsTo('/positions'), 0);
    });
  });

  group('the tunnel', () {
    test('fixes taken with no server are kept, never dropped', () async {
      for (var i = 0; i < 6; i++) {
        backend.offline('/positions');
      }
      await gps.start('trip-1');

      for (var i = 0; i < 6; i++) {
        await fix();
      }

      expect(gps.state, isA<GpsBuffering>());
      expect(gps.state.buffered, 6);
      expect(await queue.count(), 6);
      expect(await queue.failed(), isEmpty);
    });

    test('coming out of the tunnel replays every one of them', () async {
      for (var i = 0; i < 4; i++) {
        backend.offline('/positions');
      }
      await gps.start('trip-1');
      for (var i = 0; i < 4; i++) {
        await fix();
      }
      expect(await queue.count(), 4);

      backend.clearScripts();
      for (var i = 0; i < 4; i++) {
        backend.on('/positions', status: 201, body: positionResponse());
      }

      await sync.sync();

      expect(await queue.count(), 0);
      expect(await queue.failed(), isEmpty);
      expect(sync.state, isA<SyncEmpty>());
    });

    test('a replayed fix carries when it was taken, not when it was sent',
        () async {
      backend.offline('/positions');
      await gps.start('trip-1');
      await fix(at: DateTime.utc(2026, 8, 9, 7, 30));

      backend.clearScripts();
      backend.on('/positions', status: 201, body: positionResponse());
      await sync.sync();

      expect(backend.bodyFor('/positions')?['recorded_at'],
          '2026-08-09T07:30:00.000Z');
    });

    test('every attempt at the same fix carries the same key', () async {
      backend.offline('/positions');
      await gps.start('trip-1');
      await fix();

      backend.clearScripts();
      backend.offline('/positions');
      await sync.sync();
      backend.on('/positions', status: 201, body: positionResponse());
      await sync.sync();

      final keys = backend
          .bodiesFor('/positions')
          .map((b) => b['idempotency_key'])
          .toSet();

      expect(backend.callsTo('/positions'), greaterThan(1));
      expect(
        keys,
        hasLength(1),
        reason: 'the server can only absorb a replay it can recognise',
      );
    });

    test('a duplicate the server already holds is not a failure', () async {
      backend.offline('/positions');
      await gps.start('trip-1');
      await fix();

      // 200 with a null payload: how the backend says "already recorded".
      backend.clearScripts();
      backend.on('/positions', status: 200, body: positionResponse(data: null));
      await sync.sync();

      expect(await queue.count(), 0);
      expect(await queue.failed(), isEmpty);
      expect(sync.state, isA<SyncEmpty>());
    });

    test('a long outage keeps its order', () async {
      for (var i = 0; i < 5; i++) {
        backend.offline('/positions');
      }
      await gps.start('trip-1');
      for (var i = 0; i < 5; i++) {
        await fix(at: DateTime.utc(2026, 8, 9, 8, i));
      }

      backend.clearScripts();
      for (var i = 0; i < 5; i++) {
        backend.on('/positions', status: 201, body: positionResponse());
      }
      await sync.sync();

      // Every fix taken during the outage also costs one failed attempt at the
      // head of the queue, which is how the app finds out the network is back.
      // The replay itself is the last five.
      final sentTimes = backend
          .bodiesFor('/positions')
          .map((b) => b['recorded_at'])
          .toList()
          .sublist(backend.callsTo('/positions') - 5);

      expect(sentTimes, [
        '2026-08-09T08:00:00.000Z',
        '2026-08-09T08:01:00.000Z',
        '2026-08-09T08:02:00.000Z',
        '2026-08-09T08:03:00.000Z',
        '2026-08-09T08:04:00.000Z',
      ]);
    });
  });

  group('the server declining a reading', () {
    test('an implausible position is dropped silently', () async {
      backend.on('/positions',
          status: 409, body: positionRejected('implausible speed'));
      await gps.start('trip-1');

      await fix();

      expect(await queue.count(), 0);
      expect(
        await queue.failed(),
        isEmpty,
        reason: 'the server is right about its own map, and there is nothing '
            'the driver could do about it',
      );
    });

    test('a trip closed underneath the stream stops everything', () async {
      backend.on('/positions', status: 409, body: {
        'success': false,
        'message':
            'Positions are only accepted for a running trip. This trip is COMPLETED.',
        'data': null,
        'errors': null,
        'code': 409,
      });
      await gps.start('trip-1');

      await fix();

      expect(gps.state, isA<GpsIdle>());
      expect(await queue.count(), 0);
    });
  });
}
