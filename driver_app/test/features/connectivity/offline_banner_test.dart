import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/design_system/ctms_colors.dart';
import 'package:ctms_driver/core/widgets/persistent_banner.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';

/// C2 through the real app.
///
/// The cubit tests prove the state machine and the component tests prove the
/// banner; these prove the two are joined — a correct cubit behind a banner
/// nobody rebuilt is a driver queueing boardings with no idea why.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  testWidgets('no banner while the API answers', (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    expect(app.session.state.isAuthenticated, isTrue);
    expect(find.byType(PersistentBanner), findsNothing);
  });

  testWidgets('losing the API raises the banner on whichever tab is open',
      (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    expect(
      app.connectivityCubit.state,
      Reachability.offline,
      reason: 'the cubit must follow the service before anything can render',
    );
    expect(find.byType(PersistentBanner), findsOneWidget);
    expect(find.textContaining('Offline'), findsOneWidget);

    await tester.tap(find.text('Alerts'));
    await settle(tester);

    expect(
      find.byType(PersistentBanner),
      findsOneWidget,
      reason: 'connectivity is a property of the app, not of one screen',
    );
  });

  testWidgets('the banner clears when the API answers again', (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    app.connectivity.emit(Reachability.offline);
    await settle(tester);
    expect(find.byType(PersistentBanner), findsOneWidget);

    app.connectivity.emit(Reachability.online);
    await settle(tester);

    expect(find.byType(PersistentBanner), findsNothing);
    expect(find.textContaining('Offline'), findsNothing);
  });

  testWidgets('the banner cannot be dismissed', (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    final banner = tester.widget<PersistentBanner>(find.byType(PersistentBanner));

    expect(
      banner.dismissible,
      isFalse,
      reason: 'the condition outlives the gesture; hiding it would leave a '
          'driver queueing with no sign of why',
    );
  });

  testWidgets('the banner pushes the tabs down instead of covering them',
      (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    final before = tester.getTopLeft(find.text('Trip').first).dy;

    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    expect(
      tester.getTopLeft(find.text('Trip').first).dy,
      greaterThan(before),
      reason: 'a banner that overlays hides the control being reached for',
    );
  });

  testWidgets('the sign-in screen says so too', (tester) async {
    // Sign-in is the one action that genuinely cannot be queued: there is no
    // identity to queue it under.
    app = await registerTestDependencies();
    await pumpApp(tester);

    expect(find.textContaining('No connection'), findsNothing);

    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    expect(find.textContaining('No connection'), findsOneWidget);
  });

  testWidgets('the app makes no request of its own while it sits idle',
      (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    final afterLaunch = app.backend.requests.length;

    // Half a minute of nothing happening. Any request appearing here would be
    // a poll — and CTMS has no health endpoint to poll, so a probe could only
    // be a real endpoint being used as one.
    for (var i = 0; i < 6; i++) {
      await tester.pump(const Duration(seconds: 5));
    }
    await settle(tester);

    expect(
      app.backend.requests.length,
      afterLaunch,
      reason: 'reachability is derived from traffic the app was going to make '
          'anyway; it never generates its own',
    );
  });

  testWidgets('an idle offline app stays offline — and that is the contract',
      (tester) async {
    app = await registerTestDependencies(signedIn: true);
    await pumpApp(tester);

    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    final requestsWhileOffline = app.backend.requests.length;

    for (var i = 0; i < 6; i++) {
      await tester.pump(const Duration(seconds: 5));
    }
    await settle(tester);

    expect(app.backend.requests.length, requestsWhileOffline);
    expect(
      find.byType(PersistentBanner),
      findsOneWidget,
      reason: 'with no API traffic there is no evidence the server came back, '
          'and inventing evidence is worse than showing the banner a while '
          'longer',
    );
  });

  /// Asserts the banner takes its colour from the semantic token rather than
  /// a literal, which is the only thing that makes it correct in both themes.
  Future<void> expectSemanticGround(WidgetTester tester, ThemeMode mode) async {
    app = await registerTestDependencies(
      signedIn: true,
      preferences: {'settings.themeMode': mode.name},
    );
    await pumpApp(tester);

    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    final context = tester.element(find.byType(PersistentBanner));
    final material = tester.widget<Material>(
      find.descendant(
        of: find.byType(PersistentBanner),
        matching: find.byType(Material),
      ),
    );

    expect(material.color, context.ctms.caution);
  }

  testWidgets('the banner uses the semantic token in light', (tester) async {
    await expectSemanticGround(tester, ThemeMode.light);
  });

  testWidgets('and resolves to the dark value in dark', (tester) async {
    await expectSemanticGround(tester, ThemeMode.dark);

    expect(
      CtmsColors.dark.caution,
      isNot(CtmsColors.light.caution),
      reason: 'if the two were equal the test above would pass on a literal',
    );
  });
}
