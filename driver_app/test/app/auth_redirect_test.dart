import 'package:ctms_driver/app/router/app_router.dart';
import 'package:ctms_driver/app/router/routes.dart';
import 'package:ctms_driver/features/auth/domain/session_state.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/auth_fixtures.dart';

/// The gate.
///
/// Tested as a pure function rather than by driving a widget, so every state ×
/// location pair can be checked — the combination that goes wrong is never the
/// one anybody thinks to open the app on.
void main() {
  final signedIn = <SessionState>[
    SessionAuthenticated(sessionFixture()),
    SessionOffline(sessionFixture()),
    SessionRefreshing(sessionFixture()),
  ];

  final signedOut = <SessionState>[
    const SessionUnauthenticated(),
    const SessionAuthenticating(),
    const SessionLoginFailed('Invalid email or password.'),
  ];

  group('while initialising', () {
    test('holds the splash', () {
      expect(redirectFor(const SessionInitialising(), Routes.splash), isNull);
    });

    test('pulls every other location back to the splash', () {
      for (final location in [Routes.trip, Routes.login, Routes.me]) {
        expect(
          redirectFor(const SessionInitialising(), location),
          Routes.splash,
          reason: 'flashing a login screen at a driver who is in fact signed '
              'in is worse than a moment of splash',
        );
      }
    });
  });

  group('signed out', () {
    test('every signed-out state lands on login', () {
      for (final state in signedOut) {
        expect(redirectFor(state, Routes.trip), Routes.login);
        expect(redirectFor(state, Routes.me), Routes.login);
      }
    });

    test('login itself is not redirected', () {
      for (final state in signedOut) {
        expect(redirectFor(state, Routes.login), isNull);
      }
    });

    test('a deep link into the app is refused', () {
      expect(
        redirectFor(const SessionUnauthenticated(), '/trip/inspection'),
        Routes.login,
      );
    });
  });

  group('signed in', () {
    test('every signed-in state can reach the app', () {
      for (final state in signedIn) {
        expect(redirectFor(state, Routes.trip), isNull);
        expect(redirectFor(state, Routes.alerts), isNull);
      }
    });

    test('a refresh does not bounce the driver out of their screen', () {
      expect(
        redirectFor(SessionRefreshing(sessionFixture()), '/trip/inspection'),
        isNull,
        reason: 'a token exchange mid-inspection must be invisible',
      );
    });

    test('a signed-in driver cannot sit on login or splash', () {
      for (final state in signedIn) {
        expect(redirectFor(state, Routes.login), Routes.trip);
        expect(redirectFor(state, Routes.splash), Routes.trip);
        expect(redirectFor(state, Routes.sessionExpired), Routes.trip);
      }
    });
  });

  group('expired', () {
    const expired = SessionExpired(SessionEndReason.refreshRefused);

    test('everything goes to the expired screen', () {
      for (final location in [Routes.trip, Routes.login, '/trip/inspection']) {
        expect(redirectFor(expired, location), Routes.sessionExpired);
      }
    });

    test('the expired screen itself is stable', () {
      expect(redirectFor(expired, Routes.sessionExpired), isNull);
    });
  });

  group('deny by default', () {
    test('an unknown route is treated as private', () {
      expect(
        redirectFor(const SessionUnauthenticated(), '/some/new/screen'),
        Routes.login,
        reason: 'a route added without thinking about auth must land behind '
            'the gate, not in front of it',
      );
    });

    test('only three locations are public', () {
      expect(Routes.public, {
        Routes.splash,
        Routes.login,
        Routes.sessionExpired,
      });
    });

    test('a public prefix does not open its siblings', () {
      expect(
        Routes.isPublic('/loginish'),
        isFalse,
        reason: 'prefix matching that ignores the boundary would make '
            '/login-anything public',
      );
      expect(Routes.isPublic('/login/help'), isTrue);
    });
  });
}
