import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/sos/sos_service.dart';
import 'package:ctms_driver/core/sync/drift_sync_queue.dart';
import 'package:ctms_driver/core/sync/sync_cubit.dart';
import 'package:ctms_driver/core/sync/sync_database.dart';
import 'package:ctms_driver/core/sync/sync_engine.dart';
import 'package:ctms_driver/features/incidents/data/incident_api.dart';
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

Map<String, dynamic> incidentCreated({String id = 'incident-1'}) => {
      'success': true,
      'message': 'Emergency alert raised. The transport office has been notified.',
      'code': 201,
      'data': {'id': id, 'status': 'OPEN', 'severity': 'CRITICAL'},
    };

Map<String, dynamic> refusal(String message) => {
      'success': false,
      'message': message,
      'data': null,
      'errors': null,
      'code': 409,
    };

/// The emergency path.
///
/// The behaviour that matters is the ordering: written down before it is sent,
/// one key for the life of the alert, and never described as sent when it has
/// not been.
void main() {
  late SyncDatabase db;
  late DriftSyncQueue queue;
  late FakeBackend backend;
  late SyncCubit sync;
  late SosService sos;

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

    final api = IncidentApi(client);

    sync = SyncCubit(
      queue: queue,
      engine: SyncEngine(
        queue: queue,
        connectivity: reach,
        logger: SilentLogger(),
        senders: {SyncKinds.incident: (a) => client.post('/incidents', body: a.payload)},
        gap: Duration.zero,
      ),
      connectivity: reach,
    );

    sos = SosService(
      api: api,
      queue: queue,
      sync: sync,
      logger: SilentLogger(),
      contact: const EmergencyContact(phone: '+911234567890'),
    );
  });

  tearDown(() async {
    await sos.close();
    await sync.close();
    await db.close();
  });

  test('the alert is written down before it is sent', () async {
    backend.offline('/incidents');

    await sos.raise();

    expect(sos.state, isA<SosQueued>());
    expect(
      await queue.count(),
      1,
      reason: 'a phone that dies mid-transmission has still recorded that the '
          'driver pressed the button',
    );
  });

  test('a sent alert leaves the queue', () async {
    backend.on('/incidents', status: 201, body: incidentCreated());

    await sos.raise();

    expect(sos.state, isA<SosSent>());
    expect((sos.state as SosSent).incidentId, 'incident-1');
    expect(await queue.count(), 0);
  });

  test('a queued alert replays under the key it was raised with', () async {
    backend.offline('/incidents');
    await sos.raise();

    final queued = (await queue.pending()).single;

    backend.on('/incidents', status: 201, body: incidentCreated());
    await sync.sync();

    expect(backend.bodyFor('/incidents')?['idempotency_key'], queued.idempotencyKey);
    expect(
      await queue.count(),
      0,
      reason: 'one press of the button is one alert, however many attempts it '
          'took to deliver',
    );
  });

  test('it needs no trip, no position and no description', () async {
    backend.on('/incidents', status: 201, body: incidentCreated());

    await sos.raise();

    final body = backend.bodyFor('/incidents')!;

    expect(body['incident_type'], 'SOS');
    expect(body.containsKey('trip_id'), isFalse);
    expect(body.containsKey('latitude'), isFalse);
    expect(
      body.containsKey('description'),
      isFalse,
      reason: 'life safety is the one class the server accepts without one, '
          'and a driver in that situation is not typing',
    );
  });

  test('trip and position are attached when they happen to exist', () async {
    backend.on('/incidents', status: 201, body: incidentCreated());

    await sos.raise(tripId: 'trip-1', latitude: 16.92, longitude: 82.01);

    final body = backend.bodyFor('/incidents')!;

    expect(body['trip_id'], 'trip-1');
    expect(body['latitude'], 16.92);
    expect(body['reported_at'], isNotNull);
  });

  test('a refusal is shown verbatim and not left queued', () async {
    backend.on('/incidents', status: 409,
        body: refusal('An alert is already open for this trip.'));

    await sos.raise();

    expect(sos.state, isA<SosRefused>());
    expect((sos.state as SosRefused).message,
        'An alert is already open for this trip.');
    expect(
      await queue.pending(),
      isEmpty,
      reason: 'the server has decided; retrying only asks the same question',
    );
  });

  test('the queued state carries the position for the SMS fallback', () async {
    backend.offline('/incidents');

    await sos.raise(latitude: 16.92, longitude: 82.01);

    final state = sos.state as SosQueued;

    expect(state.hasPosition, isTrue);
    expect(state.latitude, 16.92);
  });

  test('no configured number means no pretence of calling', () async {
    final unconfigured = SosService(
      api: IncidentApi(ApiClient(
        baseUrl: 'http://localhost/api/v1',
        logger: SilentLogger(),
        retryDelays: const [],
      )),
      queue: queue,
      sync: sync,
      logger: SilentLogger(),
      contact: const EmergencyContact(),
    );
    addTearDown(unconfigured.close);

    expect(unconfigured.contact.isConfigured, isFalse);
    expect(await unconfigured.call(), isFalse);
    expect(await unconfigured.sendSms(), isFalse);
  });
}
