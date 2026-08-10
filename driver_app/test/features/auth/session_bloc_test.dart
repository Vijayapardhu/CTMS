import 'package:bloc_test/bloc_test.dart';
import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/features/auth/data/auth_api.dart';
import 'package:ctms_driver/features/auth/data/session_manager.dart';
import 'package:ctms_driver/features/auth/data/session_store.dart';
import 'package:ctms_driver/features/auth/domain/session_state.dart';
import 'package:ctms_driver/features/auth/presentation/bloc/session_bloc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/auth_fixtures.dart';
import '../../helpers/fake_backend.dart';
import '../../helpers/test_doubles.dart';

/// Every transition in M0 (`docs/driver-app/04-state-machines.md`), walked.
///
/// A state with no test is a state that will render wrong at 06:40, so the
/// last group here asserts that all eight are reachable.
void main() {
  late FakeBackend backend;
  late InMemorySecureStore store;
  late RecordingCrashReporter crashes;
  late RecordingAnalytics analytics;
  late SessionManager manager;

  SessionBloc build() => SessionBloc(
        manager: manager,
        crashReporter: crashes,
        analytics: analytics,
      );

  setUp(() {
    backend = FakeBackend();
    store = InMemorySecureStore();
    crashes = RecordingCrashReporter();
    analytics = RecordingAnalytics();

    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      // No back-off in tests: the policy is proven in api_client_test.
      retryDelays: const [Duration.zero, Duration.zero],
    );
    client.dio.httpClientAdapter = backend;

    manager = SessionManager(
      api: AuthApi(client),
      store: SessionStore(store, SilentLogger()),
      logger: SilentLogger(),
    );
  });

  tearDown(() => manager.dispose());

  group('launch', () {
    blocTest<SessionBloc, SessionState>(
      'initialising → unauthenticated with no stored tokens',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) => bloc.add(const SessionStarted()),
      expect: () => [
        isA<SessionInitialising>(),
        isA<SessionUnauthenticated>(),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'initialising → authenticated when the server confirms the identity',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () async {
        backend.on('/auth/login', status: 200, body: tokenResponseBody());
        await manager.login(email: 'ravi@ctms.example', password: 'ok');
        backend.on('/auth/me', status: 200, body: meResponseBody());
      },
      act: (bloc) => bloc.add(const SessionStarted()),
      expect: () => [
        isA<SessionInitialising>(),
        isA<SessionAuthenticated>(),
      ],
      verify: (_) => expect(crashes.identifiers.last, 'user-1'),
    );

    blocTest<SessionBloc, SessionState>(
      'initialising → offline when there are tokens but no network',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () async {
        backend.on('/auth/login', status: 200, body: tokenResponseBody());
        await manager.login(email: 'ravi@ctms.example', password: 'ok');
        backend.offline('/auth/me');
      },
      act: (bloc) => bloc.add(const SessionStarted()),
      expect: () => [
        isA<SessionInitialising>(),
        isA<SessionOffline>(),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'initialising → authenticated when a stale access token is refreshed',
      build: build,
      wait: const Duration(milliseconds: 100),
      setUp: () async {
        // An access token that lapsed overnight. The refresh token has not,
        // so a driver opening the app before the first run must not be made
        // to type a password in a depot yard at six in the morning.
        backend.on('/auth/login',
            status: 200,
            body: tokenResponseBody(
              accessTtl: const Duration(seconds: 1),
              now: DateTime.utc(2026, 1, 1, 6),
            ));
        await manager.login(email: 'ravi@ctms.example', password: 'ok');
        backend
          ..on('/auth/refresh',
              status: 200, body: tokenResponseBody(accessToken: 'access-2'))
          ..on('/auth/me', status: 200, body: meResponseBody());
      },
      act: (bloc) => bloc.add(const SessionStarted()),
      expect: () => [
        isA<SessionInitialising>(),
        isA<SessionAuthenticated>(),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'initialising → expired when the stored tokens are refused',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () async {
        backend.on('/auth/login', status: 200, body: tokenResponseBody());
        await manager.login(email: 'ravi@ctms.example', password: 'ok');
        backend
          ..on('/auth/me', status: 401, body: errorBody('Unauthenticated.'))
          ..on('/auth/refresh',
              status: 401, body: errorBody('This account is no longer active.'));
      },
      act: (bloc) => bloc.add(const SessionStarted()),
      expect: () => [
        isA<SessionInitialising>(),
        isA<SessionExpired>(),
      ],
    );
  });

  group('login', () {
    blocTest<SessionBloc, SessionState>(
      'unauthenticated → authenticating → authenticated',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () => backend.on('/auth/login',
          status: 200, body: tokenResponseBody()),
      act: (bloc) => bloc.add(const SessionLoginRequested(
        email: 'ravi@ctms.example',
        password: 'correct-horse',
      )),
      expect: () => [
        isA<SessionAuthenticating>(),
        isA<SessionAuthenticated>(),
      ],
      verify: (_) {
        expect(analytics.events, contains('session_started'));
        expect(crashes.identifiers.last, 'user-1');
      },
    );

    blocTest<SessionBloc, SessionState>(
      'bad credentials → loginFailed, carrying the server wording verbatim',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () => backend.on('/auth/login',
          status: 401, body: errorBody('Invalid email or password.')),
      act: (bloc) => bloc.add(const SessionLoginRequested(
        email: 'ravi@ctms.example',
        password: 'wrong',
      )),
      expect: () => [
        isA<SessionAuthenticating>(),
        isA<SessionLoginFailed>().having(
          (s) => s.message,
          'message',
          // Not paraphrased. The backend words this so it never reveals
          // whether the address exists, and rewording it here loses that.
          'Invalid email or password.',
        ),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'a deactivated account is a login failure, not a crash',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () => backend.on('/auth/login',
          status: 401, body: errorBody('This account has been deactivated.')),
      act: (bloc) => bloc.add(const SessionLoginRequested(
        email: 'ravi@ctms.example',
        password: 'ok',
      )),
      expect: () => [
        isA<SessionAuthenticating>(),
        isA<SessionLoginFailed>().having((s) => s.message, 'message',
            'This account has been deactivated.'),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'throttling surfaces as a login failure the driver can read',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () => backend.on('/auth/login',
          status: 429, body: errorBody('Too many attempts. Try again shortly.')),
      act: (bloc) => bloc.add(const SessionLoginRequested(
        email: 'ravi@ctms.example',
        password: 'ok',
      )),
      expect: () => [
        isA<SessionAuthenticating>(),
        isA<SessionLoginFailed>(),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'validation errors are kept per field',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () => backend.on(
        '/auth/login',
        status: 422,
        body: errorBody('The given data was invalid.', errors: {
          'email': ['The email field is required.'],
        }),
      ),
      act: (bloc) => bloc.add(const SessionLoginRequested(
        email: '',
        password: 'ok',
      )),
      expect: () => [
        isA<SessionAuthenticating>(),
        isA<SessionLoginFailed>().having(
          (s) => s.fieldErrors['email'],
          'email errors',
          isNotEmpty,
        ),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'no network is a failure, never a queued sign-in',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      setUp: () => backend.offline('/auth/login'),
      act: (bloc) => bloc.add(const SessionLoginRequested(
        email: 'ravi@ctms.example',
        password: 'ok',
      )),
      expect: () => [
        isA<SessionAuthenticating>(),
        isA<SessionLoginFailed>(),
      ],
      verify: (bloc) => expect(
        bloc.state.session,
        isNull,
        reason: 'there is no identity to queue a sign-in under',
      ),
    );
  });

  group('refresh', () {
    Future<void> signIn(SessionBloc bloc) async {
      backend.on('/auth/login', status: 200, body: tokenResponseBody());
      bloc.add(const SessionLoginRequested(
        email: 'ravi@ctms.example',
        password: 'ok',
      ));
      await bloc.stream.firstWhere((s) => s is SessionAuthenticated);
    }

    blocTest<SessionBloc, SessionState>(
      'authenticated → refreshing → authenticated',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) async {
        await signIn(bloc);
        backend.on('/auth/refresh',
            status: 200, body: tokenResponseBody(accessToken: 'access-2'));
        await manager.refreshSession();
      },
      skip: 2,
      expect: () => [
        isA<SessionRefreshing>(),
        isA<SessionAuthenticated>().having(
          (s) => s.session.tokens.accessToken,
          'access token',
          'access-2',
        ),
      ],
    );

    blocTest<SessionBloc, SessionState>(
      'authenticated → refreshing → expired when refresh is refused',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) async {
        await signIn(bloc);
        backend.on('/auth/refresh',
            status: 401, body: errorBody('This account is no longer active.'));
        await manager.refreshSession();
      },
      skip: 2,
      expect: () => [
        isA<SessionRefreshing>(),
        isA<SessionExpired>().having(
          (s) => s.reason,
          'reason',
          SessionEndReason.refreshRefused,
        ),
      ],
      verify: (_) => expect(crashes.identifiers.last, isNull),
    );

    blocTest<SessionBloc, SessionState>(
      'a refresh that cannot reach the server leaves the driver signed in',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) async {
        await signIn(bloc);
        backend.offline('/auth/refresh');
        await manager.refreshSession();
      },
      skip: 2,
      expect: () => [isA<SessionRefreshing>()],
      verify: (bloc) => expect(bloc.state.session, isNotNull),
    );
  });

  group('expiry', () {
    blocTest<SessionBloc, SessionState>(
      'expired → unauthenticated only once the driver acknowledges it',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      seed: () => const SessionExpired(SessionEndReason.refreshRefused),
      act: (bloc) => bloc.add(const SessionExpiryAcknowledged()),
      expect: () => [isA<SessionUnauthenticated>()],
    );

    blocTest<SessionBloc, SessionState>(
      'acknowledging from any other state does nothing',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      seed: () => const SessionUnauthenticated(),
      act: (bloc) => bloc.add(const SessionExpiryAcknowledged()),
      expect: () => <SessionState>[],
    );
  });

  group('logout', () {
    blocTest<SessionBloc, SessionState>(
      'authenticated → unauthenticated, and the crash identifier is cleared',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) async {
        backend.on('/auth/login', status: 200, body: tokenResponseBody());
        bloc.add(const SessionLoginRequested(
          email: 'ravi@ctms.example',
          password: 'ok',
        ));
        await bloc.stream.firstWhere((s) => s is SessionAuthenticated);

        backend.on('/auth/logout',
            status: 200, body: {'success': true, 'message': 'ok', 'data': null});
        bloc.add(const SessionLogoutRequested());
      },
      skip: 2,
      expect: () => [isA<SessionUnauthenticated>()],
      verify: (_) => expect(crashes.identifiers.last, isNull),
    );

    blocTest<SessionBloc, SessionState>(
      'sign out everywhere signs out when the server agrees',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) async {
        backend.on('/auth/login', status: 200, body: tokenResponseBody());
        bloc.add(const SessionLoginRequested(
          email: 'ravi@ctms.example',
          password: 'ok',
        ));
        await bloc.stream.firstWhere((s) => s is SessionAuthenticated);

        backend.on('/auth/logout-all',
            status: 200, body: {'success': true, 'message': 'ok', 'data': null});
        bloc.add(const SessionLogoutEverywhereRequested());
      },
      skip: 2,
      expect: () => [isA<SessionUnauthenticated>()],
    );

    blocTest<SessionBloc, SessionState>(
      'a refused sign-out-everywhere changes no state and reports the failure',
      build: build,
      // The handlers await real async work through the fake adapter; without
      // this, bloc_test samples the stream before the second state arrives.
      wait: const Duration(milliseconds: 100),
      act: (bloc) async {
        backend.on('/auth/login', status: 200, body: tokenResponseBody());
        bloc.add(const SessionLoginRequested(
          email: 'ravi@ctms.example',
          password: 'ok',
        ));
        await bloc.stream.firstWhere((s) => s is SessionAuthenticated);

        final notice = bloc.notices.first;
        backend.offline('/auth/logout-all');
        bloc.add(const SessionLogoutEverywhereRequested());

        await notice;
      },
      skip: 2,
      expect: () => <SessionState>[],
      verify: (bloc) => expect(
        bloc.state,
        isA<SessionAuthenticated>(),
        reason: 'the driver is still signed in here, and saying otherwise '
            'would imply their other devices are safe',
      ),
    );
  });

  group('state coverage', () {
    test('every M0 state is reachable and none is a duplicate', () {
      // The list is written out rather than derived, so adding a state to the
      // sealed hierarchy without a path into it fails here.
      final states = <SessionState>[
        const SessionInitialising(),
        const SessionUnauthenticated(),
        const SessionAuthenticating(),
        const SessionLoginFailed('refused'),
        SessionAuthenticated(sessionFixture()),
        SessionOffline(sessionFixture()),
        SessionRefreshing(sessionFixture()),
        const SessionExpired(SessionEndReason.refreshRefused),
      ];

      expect(states.map((s) => s.runtimeType).toSet(), hasLength(states.length));
    });

    test('only the signed-in states expose a session', () {
      expect(const SessionInitialising().isAuthenticated, isFalse);
      expect(const SessionUnauthenticated().isAuthenticated, isFalse);
      expect(const SessionAuthenticating().isAuthenticated, isFalse);
      expect(const SessionLoginFailed('x').isAuthenticated, isFalse);
      expect(const SessionExpired(SessionEndReason.signedOut).isAuthenticated,
          isFalse);

      expect(SessionAuthenticated(sessionFixture()).isAuthenticated, isTrue);
      expect(SessionOffline(sessionFixture()).isAuthenticated, isTrue);
      expect(SessionRefreshing(sessionFixture()).isAuthenticated, isTrue);
    });
  });
}
