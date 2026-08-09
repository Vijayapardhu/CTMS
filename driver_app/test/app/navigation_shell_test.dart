import 'package:ctms_driver/app/router/routes.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  group('navigation shell', () {
    late TestApp app;

    // Registered inside each test body, not in setUp. A setUp callback runs
    // outside the widget test's fake-async zone, so timers created there —
    // the API client's retry back-off — are scheduled on a clock that `pump`
    // never advances, and a read that retries would never finish.
    tearDown(() async => app.dispose());

    Future<void> boot(WidgetTester tester) async {
      app = await registerTestDependencies(signedIn: true);
      await pumpApp(tester);
    }

    testWidgets('opens on the trip tab', (tester) async {
      await boot(tester);

      expect(find.text('Trip'), findsWidgets);
      expect(
        find.byType(NavigationBar),
        findsOneWidget,
      );
    });

    testWidgets('shows the four spec destinations and no others',
        (tester) async {
      await boot(tester);

      final bar = tester.widget<NavigationBar>(find.byType(NavigationBar));

      expect(bar.destinations, hasLength(4));
      expect(
        bar.destinations
            .cast<NavigationDestination>()
            .map((d) => d.label)
            .toList(),
        ['Trip', 'Map', 'Alerts', 'Me'],
      );
    });

    testWidgets('every tab label is always visible', (tester) async {
      await boot(tester);

      // The behaviour comes from the theme, not the widget, so the widget's
      // own field is null — assert the value that actually takes effect.
      final theme = Theme.of(
        tester.element(find.byType(NavigationBar)),
      ).navigationBarTheme;

      expect(
        theme.labelBehavior,
        NavigationDestinationLabelBehavior.alwaysShow,
        reason: 'a driver must not have to guess what an icon means',
      );

      for (final label in ['Trip', 'Map', 'Alerts', 'Me']) {
        expect(find.text(label), findsWidgets);
      }
    });

    testWidgets('switching tab changes the screen', (tester) async {
      await boot(tester);

      await tester.tap(find.text('Me'));
      await settle(tester);

      expect(find.text('Appearance'), findsOneWidget);
    });

    testWidgets('returning to a tab keeps its own state', (tester) async {
      await boot(tester);

      await tester.tap(find.text('Me'));
      await settle(tester);
      await tester.tap(find.text('Trip'));
      await settle(tester);
      await tester.tap(find.text('Me'));
      await settle(tester);

      expect(find.text('Appearance'), findsOneWidget);
    });

    testWidgets('no offline banner while the API is reachable', (tester) async {
      await boot(tester);

      expect(find.textContaining('Offline'), findsNothing);
    });

    testWidgets('the offline banner appears on any tab', (tester) async {
      await boot(tester);

      app.connectivity.emit(Reachability.offline);
      await settle(tester);

      expect(find.textContaining('Offline'), findsOneWidget);

      await tester.tap(find.text('Alerts'));
      await settle(tester);

      expect(
        find.textContaining('Offline'),
        findsOneWidget,
        reason: 'connectivity is a property of the app, not of one screen',
      );
    });

    testWidgets('the offline banner clears on recovery', (tester) async {
      await boot(tester);

      app.connectivity.emit(Reachability.offline);
      await settle(tester);
      app.connectivity.emit(Reachability.online);
      await settle(tester);

      expect(find.textContaining('Offline'), findsNothing);
    });
  });

  group('Routes', () {
    test('declares exactly the four roots from the screen inventory', () {
      expect(Routes.tabs, [Routes.trip, Routes.map, Routes.alerts, Routes.me]);
    });

    test('maps a nested location back to its owning tab', () {
      expect(Routes.tabIndexOf('/alerts/42'), 2);
    });

    test('an unknown location resolves to the trip tab', () {
      expect(
        Routes.tabIndexOf('/nowhere'),
        0,
        reason: 'the default destination is the one the driver actually works '
            'in',
      );
    });
  });
}
