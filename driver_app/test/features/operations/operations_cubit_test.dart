import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/sync/drift_sync_queue.dart';
import 'package:ctms_driver/core/sync/sync_cubit.dart';
import 'package:ctms_driver/core/sync/sync_database.dart';
import 'package:ctms_driver/core/sync/sync_engine.dart';
import 'package:ctms_driver/features/operations/data/operations_api.dart';
import 'package:ctms_driver/features/operations/presentation/bloc/operations_cubit.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fake_backend.dart';
import '../../helpers/test_doubles.dart';

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

Map<String, dynamic> occupancyResponse({int occupied = 1, int capacity = 40}) {
  return {
    'success': true,
    'message': 'Boarding recorded.',
    'code': 201,
    'data': {'occupied': occupied, 'capacity': capacity},
  };
}

Map<String, dynamic> refusal(String message) {
  return {
    'success': false,
    'message': message,
    'data': null,
    'errors': null,
    'code': 409,
  };
}

/// M4 and the stop actions.
///
/// The counter is the part a driver watches while counting people through a
/// door, so most of this is about it staying truthful: moving instantly,
/// deferring to the server, and never quietly absorbing a refusal.
void main() {
  late SyncDatabase db;
  late DriftSyncQueue queue;
  late FakeBackend backend;
  late SyncCubit sync;
  late OperationsCubit ops;

  setUp(() {
    db = SyncDatabase.forTesting(NativeDatabase.memory());
    queue = DriftSyncQueue(db, SilentLogger());
    backend = FakeBackend();
    final reach = _Reach();

    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      retryDelays: const [],
    )..dio.httpClientAdapter = backend;

    final api = OperationsApi(client);

    sync = SyncCubit(
      queue: queue,
      engine: SyncEngine(
        queue: queue,
        connectivity: reach,
        logger: SilentLogger(),
        senders: {
          SyncKinds.board: (a) => api.board(
            a.payload['trip_id']! as String,
            idempotencyKey: a.idempotencyKey,
          ),
        },
        gap: Duration.zero,
      ),
      connectivity: reach,
    );

    ops = OperationsCubit(api: api, queue: queue, sync: sync)
      ..adopt(tripId: 'trip-1', occupied: 0, capacity: 40);
  });

  tearDown(() async {
    await ops.close();
    await sync.close();
    await db.close();
  });

  group('the counter', () {
    test('moves before the server answers', () async {
      backend.on('/board', status: 201, body: occupancyResponse(occupied: 1));

      final pending = ops.board();
      expect(
        ops.state.occupied,
        1,
        reason: 'a driver counting heads cannot wait for a round trip',
      );
      await pending;
    });

    test('defers to the server\'s figure when it arrives', () async {
      // The office boarded two more from another device.
      backend.on('/board', status: 201, body: occupancyResponse(occupied: 7));

      await ops.board();

      expect(ops.state.occupied, 7);
      expect(ops.state.pending, 0);
    });

    test('takes the tap back when the server refuses', () async {
      backend.on('/board', status: 409, body: refusal('The bus is full.'));

      await ops.board();

      expect(ops.state.occupied, 0, reason: 'the server did not accept it');
      expect(ops.state.refusal, 'The bus is full.');
      expect(ops.state.rejected, 1);
    });

    test('a refusal is the server\'s words, not a paraphrase', () async {
      backend.on(
        '/board',
        status: 409,
        body: refusal('This trip is not running.'),
      );

      await ops.board();

      expect(ops.state.refusal, 'This trip is not running.');
    });

    test('alighting cannot take the count below zero', () async {
      backend.on('/alight', status: 201, body: occupancyResponse(occupied: 0));

      await ops.alight();

      expect(ops.state.occupied, 0);
    });

    test('capacity is carried so the button can stop at full', () async {
      backend.on('/board', status: 201, body: occupancyResponse(occupied: 40));

      await ops.board();

      expect(ops.state.isFull, isTrue);
    });
  });

  group('offline', () {
    test('a tap in a dead spot is queued, not lost', () async {
      backend.offline('/board');

      await ops.board();

      expect(await queue.count(), 1);
      expect(
        ops.state.occupied,
        1,
        reason: 'the driver counted a real person; the count stands',
      );
      expect(ops.state.pending, 0);
    });

    test('the queued tap replays under the key it was given', () async {
      backend.offline('/board');
      await ops.board();

      final queued = (await queue.pending()).single;

      backend.on('/board', status: 201, body: occupancyResponse(occupied: 1));
      await sync.sync();

      expect(
        backend.bodyFor('/board')?['idempotency_key'],
        queued.idempotencyKey,
        reason: 'a new key on replay is how one boarding becomes two',
      );
      expect(await queue.count(), 0);
    });
  });

  group('stop and trip actions', () {
    test(
      'arriving reports the server\'s refusal rather than succeeding',
      () async {
        backend.on(
          '/arrive',
          status: 409,
          body: refusal('The bus is not near this stop.'),
        );

        final ok = await ops.arrive('stop-1');

        expect(ok, isFalse);
        expect(ops.state.refusal, 'The bus is not near this stop.');
      },
    );

    test('arriving succeeds when the server accepts', () async {
      backend.on(
        '/arrive',
        status: 201,
        body: {
          'success': true,
          'message': 'Arrival recorded.',
          'code': 201,
          'data': {'state': 'ARRIVED'},
        },
      );

      expect(await ops.arrive('stop-1'), isTrue);
      expect(ops.state.refusal, isNull);
    });

    test('skipping sends the reason the server requires', () async {
      backend.on(
        '/skip',
        status: 201,
        body: {
          'success': true,
          'message': 'Stop marked as skipped.',
          'code': 201,
          'data': {'state': 'SKIPPED'},
        },
      );

      await ops.skip('stop-1', 'Road closed by the council.');

      expect(
        backend.bodyFor('/skip')?['reason'],
        'Road closed by the council.',
      );
    });

    test('completing a trip that is already closed says so', () async {
      backend.on(
        '/complete',
        status: 409,
        body: refusal('This trip has already been completed.'),
      );

      expect(await ops.complete(), isFalse);
      expect(ops.state.refusal, 'This trip has already been completed.');
    });

    test('a second action is refused while the first is in flight', () async {
      backend.on(
        '/arrive',
        status: 201,
        body: {
          'success': true,
          'message': 'Arrival recorded.',
          'code': 201,
          'data': <String, dynamic>{},
        },
      );

      final first = ops.arrive('stop-1');
      final second = await ops.arrive('stop-1');

      expect(second, isFalse, reason: 'a double tap must not arrive twice');
      await first;
      expect(backend.callsTo('/arrive'), 1);
    });
  });

  group('reconciling', () {
    test('a poll never overwrites taps still in flight', () async {
      backend.offline('/board');
      final inFlight = ops.board();

      // A live poll lands mid-tap, carrying the figure from before it.
      ops.adopt(tripId: 'trip-1', occupied: 0, capacity: 40);
      expect(ops.state.occupied, 1);

      await inFlight;
    });
  });
}
