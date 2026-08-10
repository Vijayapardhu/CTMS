import 'package:ctms_driver/app/config/app_config.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// R4 — the profile tab.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> openProfile(WidgetTester tester, {Flavor? flavor}) async {
    app = await registerTestDependencies(
      signedIn: true,
      flavor: flavor ?? Flavor.development,
    );
    app.backend
      ..on('/trips', status: 200, body: tripsResponse())
      ..on('/service-readiness', status: 200, body: readinessResponse());

    await pumpApp(tester);
    await settle(tester);
    await tester.tap(find.text('Me'));
    await settle(tester);

    // The account, the theme choices and the developer toggle sit above
    // About and the two ways out, so everything under test starts off-screen.
    await tester.drag(find.byType(ListView), const Offset(0, -1200));
    await settle(tester);
  }

  testWidgets('a tester can see which backend the build is talking to',
      (tester) async {
    await openProfile(tester);

    // The version itself comes from a platform channel that a widget test has
    // no answer for. The environment line is the half that matters here, and
    // is the half that turns "nothing loads" into a two-second diagnosis.
    expect(find.textContaining('development build'), findsOneWidget);
    expect(find.textContaining('localhost'), findsOneWidget);
  });

  testWidgets('a production build shows a driver no plumbing', (tester) async {
    await openProfile(tester, flavor: Flavor.production);

    expect(find.textContaining('build ·'), findsNothing);
    expect(find.text('About'), findsOneWidget);
  });

  testWidgets('signing out is offered, and asks first', (tester) async {
    await openProfile(tester);

    expect(find.text('Sign out'), findsOneWidget);
    expect(find.text('Sign out of all devices'), findsOneWidget);
  });
}
