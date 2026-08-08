import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/api/api_failure.dart';
import 'package:ctms_driver/core/storage/secure_store.dart';
import 'package:ctms_driver/features/auth/data/auth_api.dart';
import 'package:ctms_driver/features/auth/data/session_manager.dart';
import 'package:ctms_driver/features/auth/data/session_store.dart';
import 'package:ctms_driver/features/auth/domain/session_state.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/auth_fixtures.dart';
import '../../helpers/fake_backend.dart';
import '../../helpers/test_doubles.dart';

void main() {
  late FakeBackend backend;
  late InMemorySecureStore store;
  late SessionManager manager;
  late DateTime now;

  SessionManager build() {
    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      // No back-off in tests: the policy is proven in api_client_test.
      retryDelays: const [Duration.zero, Duration.zero],
    );
    client.dio.httpClientAdapter = backend;

    return SessionManager(
      api: AuthApi(client),
      store: SessionStore(store, SilentLogger()),
      logger: SilentLogger(),
      clock: () => now,
    );
  }

  setUp(() {
    now = DateTime.utc(2026, 1, 1, 6, 30);
    backend = FakeBackend();
    store = InMemorySecureStore();
    manager = build();
  });

  tearDown(() => manager.dispose());

  group('login', () {
    test('stores the session and holds it', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());

      final session = await manager.login(
        email: 'ravi@ctms.example',
        password: 'correct-horse',
      );

      expect(session.user.isDriver, isTrue);
      expect(manager.current, isNotNull);
      expect(await store.read(SecureKeys.tokens), isNotNull);
      expect(await store.read(SecureKeys.user), isNotNull);
    });

    test('trims nothing from the password', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());

      await manager.login(email: 'ravi@ctms.example', password: '  spaced  ');

      final body = backend.requests.last.body as Map<String, dynamic>;
      expect(
        body['password'],
        '  spaced  ',
        reason: 'a password is bytes; trimming it silently changes the '
            'credential',
      );
    });

    test('a refusal leaves no session behind', () async {
      backend.on('/auth/login',
          status: 401, body: errorBody('Invalid email or password.'));

      await expectLater(
        manager.login(email: 'ravi@ctms.example', password: 'wrong'),
        throwsA(isA<AuthFailure>()),
      );

      expect(manager.current, isNull);
      expect(await store.read(SecureKeys.tokens), isNull);
    });

    test('a 401 on login never triggers a refresh', () async {
      backend.on('/auth/login',
          status: 401, body: errorBody('Invalid email or password.'));

      await expectLater(
        manager.login(email: 'ravi@ctms.example', password: 'wrong'),
        throwsA(isA<AuthFailure>()),
      );

      expect(
        backend.callsTo('/auth/refresh'),
        0,
        reason: 'the auth client carries no session delegate by construction',
      );
    });
  });

  group('restore', () {
    test('no stored tokens means unauthenticated', () async {
      expect(await manager.restore(), isA<SessionUnauthenticated>());
    });

    test('stored tokens confirmed by the server means authenticated', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');

      final restarted = build();
      addTearDown(restarted.dispose);
      backend.on('/auth/me', status: 200, body: meResponseBody(fullName: 'Ravi K'));

      final state = await restarted.restore();

      expect(state, isA<SessionAuthenticated>());
      expect(state.session!.user.fullName, 'Ravi K');
    });

    test('stored tokens with no network means offline, not signed out',
        () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');

      final restarted = build();
      addTearDown(restarted.dispose);
      backend.offline('/auth/me');

      final state = await restarted.restore();

      expect(
        state,
        isA<SessionOffline>(),
        reason: 'a driver at a depot with no signal must still be able to open '
            'the app they signed into last night',
      );
      expect(state.session, isNotNull);
    });

    test('a rejected token means expired, and the store is cleared', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');

      final restarted = build();
      addTearDown(restarted.dispose);
      backend
        ..on('/auth/me', status: 401, body: errorBody('Unauthenticated.'))
        ..on('/auth/refresh',
            status: 401, body: errorBody('This account is no longer active.'));

      final state = await restarted.restore();

      expect(state, isA<SessionExpired>());
      expect(await store.read(SecureKeys.tokens), isNull);
      expect(await store.read(SecureKeys.user), isNull);
    });

    test('a torn write is treated as no session', () async {
      await store.write(SecureKeys.tokens, '{"not":"valid"}');

      expect(await manager.restore(), isA<SessionUnauthenticated>());
      expect(await store.read(SecureKeys.tokens), isNull);
    });
  });

  group('refresh', () {
    Future<void> signIn({Duration ttl = const Duration(hours: 1)}) async {
      backend.on('/auth/login',
          status: 200,
          body: tokenResponseBody(accessTtl: ttl, now: DateTime.utc(2026, 1, 1, 6)));
      await manager.login(email: 'ravi@ctms.example', password: 'ok');
    }

    test('exchanges the refresh token and keeps the new pair', () async {
      await signIn();
      backend.on('/auth/refresh',
          status: 200,
          body: tokenResponseBody(accessToken: 'access-2', refreshToken: 'refresh-2'));

      expect(await manager.refreshSession(), isTrue);
      expect(manager.current!.tokens.accessToken, 'access-2');
      expect(manager.current!.tokens.refreshToken, 'refresh-2');
    });

    test('is single-flight: five simultaneous 401s make one refresh', () async {
      await signIn();
      backend.on(
        '/auth/refresh',
        status: 200,
        body: tokenResponseBody(accessToken: 'access-2'),
        delay: const Duration(milliseconds: 20),
      );

      final results = await Future.wait(
        List.generate(5, (_) => manager.refreshSession()),
      );

      expect(results, everyElement(isTrue));
      expect(
        backend.callsTo('/auth/refresh'),
        1,
        reason: 'each refresh consumes its token server-side; five racing '
            'refreshes invalidate each other',
      );
    });

    test('a refused refresh ends the session and says so', () async {
      await signIn();
      backend.on('/auth/refresh',
          status: 401, body: errorBody('This account is no longer active.'));

      final revoked = expectLater(
        manager.signals.where((s) => s is SessionRevoked),
        emits(isA<SessionRevoked>()),
      );

      expect(await manager.refreshSession(), isFalse);
      expect(manager.current, isNull);
      await revoked;
    });

    test('a refresh that cannot reach the server does NOT end the session',
        () async {
      await signIn();
      backend.offline('/auth/refresh');

      expect(await manager.refreshSession(), isFalse);
      expect(
        manager.current,
        isNotNull,
        reason: 'signing a driver out for driving through a tunnel is a bug, '
            'not a security measure',
      );
    });

    test('announces that it started, so `refreshing` is a real state',
        () async {
      await signIn();
      backend.on('/auth/refresh', status: 200, body: tokenResponseBody());

      final started = expectLater(
        manager.signals.where((s) => s is SessionRefreshStarted),
        emits(isA<SessionRefreshStarted>()),
      );

      await manager.refreshSession();
      await started;
    });

    test('a second refresh after the first completes does call the server',
        () async {
      await signIn();
      backend
        ..on('/auth/refresh',
            status: 200, body: tokenResponseBody(accessToken: 'access-2'))
        ..on('/auth/refresh',
            status: 200, body: tokenResponseBody(accessToken: 'access-3'));

      await manager.refreshSession();
      await manager.refreshSession();

      expect(backend.callsTo('/auth/refresh'), 2);
      expect(manager.current!.tokens.accessToken, 'access-3');
    });
  });

  group('pre-emptive refresh', () {
    test('a token inside the skew window is refreshed before it is used',
        () async {
      backend.on('/auth/login',
          status: 200,
          body: tokenResponseBody(
              accessTtl: const Duration(hours: 1), now: DateTime.utc(2026, 1, 1, 6)));
      await manager.login(email: 'ravi@ctms.example', password: 'ok');

      // 06:59:30 — inside the one-minute skew before a 07:00 expiry.
      now = DateTime.utc(2026, 1, 1, 6, 59, 30);
      backend.on('/auth/refresh',
          status: 200, body: tokenResponseBody(accessToken: 'access-2'));

      expect(await manager.accessToken(), 'access-2');
      expect(
        backend.callsTo('/auth/refresh'),
        1,
        reason: 'waiting for the 401 costs a round trip at exactly the moment '
            'the driver taps "boarded"',
      );
    });

    test('a healthy token is used as-is', () async {
      backend.on('/auth/login',
          status: 200,
          body: tokenResponseBody(now: DateTime.utc(2026, 1, 1, 6)));
      await manager.login(email: 'ravi@ctms.example', password: 'ok');

      expect(await manager.accessToken(), 'access-1');
      expect(backend.callsTo('/auth/refresh'), 0);
    });

    test('no session means no token and no call', () async {
      expect(await manager.accessToken(), isNull);
      expect(backend.requests, isEmpty);
    });
  });

  group('logout', () {
    test('tells the server and clears everything', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');
      backend.on('/auth/logout',
          status: 200, body: {'success': true, 'message': 'ok', 'data': null});

      await manager.logout();

      expect(backend.callsTo('/auth/logout'), 1);
      expect(manager.current, isNull);
      expect(await store.read(SecureKeys.tokens), isNull);
    });

    test('still signs out locally when the server cannot be reached', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');
      backend.offline('/auth/logout');

      await manager.logout();

      expect(
        manager.current,
        isNull,
        reason: 'leaving a driver signed in because the network was down is '
            'the worse failure',
      );
    });

    test('sign out everywhere propagates a failure instead of lying', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');
      backend.offline('/auth/logout-all');

      await expectLater(manager.logoutEverywhere(), throwsA(isA<ApiFailure>()));

      expect(
        manager.current,
        isNotNull,
        reason: 'reporting success for a revocation the server never received '
            'tells the driver their other devices are safe when they are not',
      );
    });

    test('sign out everywhere clears the session when it succeeds', () async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await manager.login(email: 'ravi@ctms.example', password: 'ok');
      backend.on('/auth/logout-all',
          status: 200, body: {'success': true, 'message': 'ok', 'data': null});

      await manager.logoutEverywhere();

      expect(manager.current, isNull);
      expect(await store.read(SecureKeys.user), isNull);
    });
  });
}
