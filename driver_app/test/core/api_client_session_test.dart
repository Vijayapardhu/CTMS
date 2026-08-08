import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/api/api_failure.dart';
import 'package:ctms_driver/core/api/session_delegate.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/auth_fixtures.dart';
import '../helpers/fake_backend.dart';
import '../helpers/test_doubles.dart';

/// A session the test drives by hand.
class ScriptedSession implements SessionDelegate {
  ScriptedSession({this.token = 'access-1', this.refreshSucceeds = true});

  String? token;
  bool refreshSucceeds;

  int refreshCalls = 0;
  int expiryCalls = 0;

  @override
  Future<String?> accessToken() async => token;

  @override
  Future<bool> refreshSession() async {
    refreshCalls++;
    if (refreshSucceeds) token = 'access-2';
    return refreshSucceeds;
  }

  @override
  Future<void> onSessionExpired() async => expiryCalls++;
}

Map<String, dynamic> okBody = {
  'success': true,
  'message': 'ok',
  'data': <String, dynamic>{},
  'code': 200,
};

void main() {
  late FakeBackend backend;

  ApiClient client({SessionDelegate? session}) {
    final c = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      session: session,
      retryDelays: const [Duration.zero, Duration.zero],
    );
    c.dio.httpClientAdapter = backend;
    return c;
  }

  setUp(() => backend = FakeBackend());

  group('bearer', () {
    test('attaches the session token', () async {
      backend.on('/trips', status: 200, body: okBody);

      await client(session: ScriptedSession()).get('/trips');

      expect(backend.bearerFor('/trips'), 'access-1');
    });

    test('sends no header when there is no session', () async {
      backend.on('/trips', status: 200, body: okBody);

      await client().get('/trips');

      expect(backend.bearerFor('/trips'), isNull);
    });

    test('an explicit bearer overrides the session', () async {
      backend.on('/auth/me', status: 200, body: meResponseBody());

      await client(session: ScriptedSession()).get('/auth/me', bearer: 'other');

      expect(backend.bearerFor('/auth/me'), 'other');
    });
  });

  group('401 recovery', () {
    test('refreshes once and replays the request', () async {
      final session = ScriptedSession();
      backend
        ..on('/trips', status: 401, body: errorBody('Unauthenticated.'))
        ..on('/trips', status: 200, body: okBody);

      await client(session: session).get('/trips');

      expect(session.refreshCalls, 1);
      expect(backend.callsTo('/trips'), 2);
      expect(
        backend.bearerFor('/trips'),
        'access-2',
        reason: 'the replay must carry the new token, not the dead one',
      );
    });

    test('a 401 that survives the refresh is not retried again', () async {
      final session = ScriptedSession();
      backend
        ..on('/trips', status: 401, body: errorBody('Unauthenticated.'))
        ..on('/trips', status: 401, body: errorBody('Unauthenticated.'));

      await expectLater(
        client(session: session).get('/trips'),
        throwsA(isA<AuthFailure>()),
      );

      expect(
        backend.callsTo('/trips'),
        2,
        reason: 'a 401 after a good refresh means a deactivated account; '
            'looping on it hammers the server and tells the driver nothing',
      );
      expect(session.refreshCalls, 1);
    });

    test('a failed refresh ends the session', () async {
      final session = ScriptedSession(refreshSucceeds: false);
      backend.on('/trips', status: 401, body: errorBody('Unauthenticated.'));

      await expectLater(
        client(session: session).get('/trips'),
        throwsA(isA<AuthFailure>()),
      );

      expect(session.expiryCalls, 1);
      expect(backend.callsTo('/trips'), 1);
    });

    test('a request with an explicit bearer never triggers a refresh', () async {
      final session = ScriptedSession();
      backend.on('/auth/logout', status: 401, body: errorBody('Unauthenticated.'));

      await expectLater(
        client(session: session).post('/auth/logout', bearer: 'dead'),
        throwsA(isA<AuthFailure>()),
      );

      expect(
        session.refreshCalls,
        0,
        reason: 'the caller chose that token deliberately; refreshing behind '
            'its back would use a different identity than it asked for',
      );
    });

    test('a client with no session never refreshes', () async {
      backend.on('/auth/login', status: 401, body: errorBody('Invalid.'));

      await expectLater(
        client().post('/auth/login', body: {'email': 'x', 'password': 'y'}),
        throwsA(isA<AuthFailure>()),
      );

      expect(backend.callsTo('/auth/refresh'), 0);
    });
  });

  group('what is and is not retried', () {
    test('a 409 is never retried', () async {
      backend.on('/trips/1/start',
          status: 409, body: errorBody('This bus is not cleared for service.'));

      await expectLater(
        client(session: ScriptedSession()).post('/trips/1/start'),
        throwsA(isA<ConflictFailure>()),
      );

      expect(
        backend.callsTo('/trips/1/start'),
        1,
        reason: 'retrying a considered refusal is how a bus gets '
            'double-boarded',
      );
    });

    test('a 403 is never retried', () async {
      backend.on('/drivers/2', status: 403, body: errorBody('Forbidden.'));

      await expectLater(
        client(session: ScriptedSession()).get('/drivers/2'),
        throwsA(isA<ForbiddenFailure>()),
      );

      expect(backend.callsTo('/drivers/2'), 1);
    });

    test('a 500 is retried through the same client', () async {
      backend
        ..on('/trips', status: 500, body: errorBody('Server error.'))
        ..on('/trips', status: 200, body: okBody);

      await client(session: ScriptedSession()).get('/trips');

      expect(backend.callsTo('/trips'), 2);
      expect(
        backend.bearerFor('/trips'),
        'access-1',
        reason: 'a retry through a fresh Dio would lose the header entirely',
      );
    });

    test('a connection failure surfaces as a NetworkFailure', () async {
      backend.offline('/trips');

      await expectLater(
        client(session: ScriptedSession()).get('/trips'),
        throwsA(isA<NetworkFailure>()),
      );
    });
  });
}
