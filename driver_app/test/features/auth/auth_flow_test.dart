import 'package:ctms_driver/features/auth/domain/session_state.dart';
import 'package:ctms_driver/features/auth/presentation/bloc/session_bloc.dart';
import 'package:ctms_driver/features/auth/presentation/login_screen.dart';
import 'package:ctms_driver/features/auth/presentation/session_expired_screen.dart';
import 'package:ctms_driver/features/auth/presentation/splash_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/auth_fixtures.dart';
import '../../helpers/test_harness.dart';

/// The whole flow, through the real widget tree and the real router.
///
/// The unit tests prove each piece; these prove the pieces are connected — a
/// correct bloc behind a redirect that never fires is still a driver stuck on
/// a splash screen.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> signIn(WidgetTester tester, {String password = 'ok'}) async {
    await tester.enterText(
      find.byType(TextFormField).first,
      'ravi@ctms.example',
    );
    await tester.enterText(find.byType(TextFormField).last, password);
    // The screen title and the button carry the same word. The button is last.
    await tester.tap(find.text('Sign in').last);

    await waitForSession(
      tester,
      (s) => s is SessionAuthenticated || s is SessionLoginFailed,
    );
    await settle(tester);
  }

  group('launching', () {
    testWidgets('with no session, the driver lands on login', (tester) async {
      app = await registerTestDependencies();

      await pumpApp(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.byType(SplashScreen), findsNothing);
    });

    testWidgets('with a stored session, the driver lands on the trip tab',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);

      await pumpApp(tester);

      expect(find.byType(NavigationBar), findsOneWidget);
      expect(find.byType(LoginScreen), findsNothing);
    });

    testWidgets('with a stored session and no network, the app still opens',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      // Nothing scripted means nothing answers: every request fails at the
      // transport layer, which is what no signal looks like to the client.
      app.backend.clearScripts();

      await pumpApp(tester);

      expect(app.session.state, isA<SessionOffline>());
      expect(
        find.byType(NavigationBar),
        findsOneWidget,
        reason: 'a driver at a depot with no signal must still reach the app',
      );
    });

    testWidgets('with refused tokens, the driver sees the expired screen',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      // The harness already queued a 200 for `/auth/me`; `on` appends, so the
      // refusal has to replace it rather than queue behind it.
      app.backend
        ..clearScripts()
        ..on('/auth/me', status: 401, body: errorBody('Unauthenticated.'));

      await pumpApp(tester);

      expect(find.byType(SessionExpiredScreen), findsOneWidget);
    });
  });

  group('signing in', () {
    testWidgets('a valid sign-in reaches the trip tab', (tester) async {
      app = await registerTestDependencies();
      await pumpApp(tester);

      app.backend.on('/auth/login', status: 200, body: tokenResponseBody());
      await signIn(tester);

      expect(find.byType(NavigationBar), findsOneWidget);
    });

    testWidgets('a refusal keeps the driver on login and shows the reason',
        (tester) async {
      app = await registerTestDependencies();
      await pumpApp(tester);

      app.backend.on('/auth/login',
          status: 401, body: errorBody('Invalid email or password.'));
      await signIn(tester, password: 'wrong');

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.text('Invalid email or password.'), findsOneWidget);
    });

    testWidgets('the refusal never says whether the address exists',
        (tester) async {
      app = await registerTestDependencies();
      await pumpApp(tester);

      app.backend.on('/auth/login',
          status: 401, body: errorBody('Invalid email or password.'));
      await signIn(tester, password: 'wrong');

      // The backend words this deliberately. If the UI ever adds its own copy
      // here, this is where it gets caught.
      expect(find.textContaining('not found'), findsNothing);
      expect(find.textContaining('No account'), findsNothing);
      expect(find.textContaining('exist'), findsNothing);
    });

    testWidgets('an empty form is refused before it reaches the server',
        (tester) async {
      app = await registerTestDependencies();
      await pumpApp(tester);

      await tester.tap(find.text('Sign in').last);
      await settle(tester);

      expect(find.text('Enter your email'), findsOneWidget);
      expect(find.text('Enter your password'), findsOneWidget);
      expect(
        app.backend.callsTo('/auth/login'),
        0,
        reason: 'a driver gets five attempts a minute; spending one on an '
            'empty form is a bug',
      );
    });

    testWidgets('a malformed email is caught locally', (tester) async {
      app = await registerTestDependencies();
      await pumpApp(tester);

      await tester.enterText(find.byType(TextFormField).first, 'ravi');
      await tester.enterText(find.byType(TextFormField).last, 'password');
      await tester.tap(find.text('Sign in').last);
      await settle(tester);

      expect(find.text('That does not look like an email address'),
          findsOneWidget);
      expect(app.backend.callsTo('/auth/login'), 0);
    });

    testWidgets('the password is obscured until the driver reveals it',
        (tester) async {
      app = await registerTestDependencies();
      await pumpApp(tester);

      TextField passwordField() => tester.widgetList<TextField>(
            find.byType(TextField),
          ).last;

      expect(passwordField().obscureText, isTrue);

      await tester.tap(find.byTooltip('Show password'));
      await settle(tester);

      expect(
        passwordField().obscureText,
        isFalse,
        reason: 'a driver who cannot see what they typed retries until the '
            'throttle locks them out',
      );
    });
  });

  group('signing out', () {
    Future<void> openMeTab(WidgetTester tester) async {
      await tester.tap(find.text('Me'));
      await settle(tester);
    }

    /// The two ways out live at the foot of the tab, below the account, the
    /// theme choices and the build identity. Scrolled to, as a driver would.
    Future<void> scrollToSignOut(WidgetTester tester) async {
      await tester.drag(find.byType(ListView), const Offset(0, -1200));
      await settle(tester);
    }

    testWidgets('sign out asks first, then returns to login', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);
      await openMeTab(tester);

      app.backend.on('/auth/logout',
          status: 200, body: {'success': true, 'message': 'ok', 'data': null});

      await scrollToSignOut(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);

      expect(find.text('Sign out?'), findsOneWidget);

      await tester.tap(find.widgetWithText(FilledButton, 'Sign out'));
      await waitForSession(tester, (s) => s is SessionUnauthenticated);
      await settle(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
    });

    testWidgets('cancelling the confirmation leaves the driver signed in',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);
      await openMeTab(tester);

      await scrollToSignOut(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      await tester.tap(find.text('Cancel'));
      await settle(tester);

      expect(app.session.state, isA<SessionAuthenticated>());
      expect(app.backend.callsTo('/auth/logout'), 0);
    });

    testWidgets('the account section shows who is signed in', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);
      await openMeTab(tester);

      expect(find.text('Ravi Kumar'), findsOneWidget);
      expect(find.textContaining('TS09-2019-0001234'), findsOneWidget);
    });
  });

  group('expiring mid-session', () {
    testWidgets('a revoked session clears the stack and cannot be swiped back',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);

      await tester.tap(find.text('Me'));
      await settle(tester);

      app.session.add(const SessionRevokedExternally(
        SessionEndReason.accountUnavailable,
      ));
      await settle(tester);

      expect(find.byType(SessionExpiredScreen), findsOneWidget);
      expect(
        find.byType(NavigationBar),
        findsNothing,
        reason: 'a tab bar offering to return to a trip that only produces '
            '401s is worse than no tab bar',
      );
    });

    testWidgets('deactivation is not described as an expiry', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);

      app.session.add(const SessionRevokedExternally(
        SessionEndReason.accountUnavailable,
      ));
      await settle(tester);

      expect(
        find.textContaining('can no longer be used on this device'),
        findsOneWidget,
        reason: 'telling a driver their session expired when the account was '
            'switched off sends them to ring the wrong person',
      );
    });

    testWidgets('the expired screen leads back to login', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);

      app.session.add(const SessionRevokedExternally(
        SessionEndReason.refreshRefused,
      ));
      await settle(tester);

      await tester.tap(find.text('Back to sign in'));
      await settle(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
    });
  });
}
