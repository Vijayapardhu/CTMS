import 'package:ctms_driver/features/gps/data/location_source.dart';
import 'package:ctms_driver/features/gps/domain/gps_state.dart';
import 'package:ctms_driver/features/gps/presentation/widgets/gps_status_pill.dart';
import 'package:ctms_driver/features/trip/domain/trip_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// What M3 looks like on R1.
///
/// The machine itself — buffering, replay, idempotency, what a refusal means —
/// is proved in `gps_cubit_test.dart` against the real queue. What is left for
/// this file is the part only a rendered tree can answer: that the stream is
/// tied to the trip rather than to a screen, and that the pill says its state
/// in words.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> openTrip(WidgetTester tester, {required String status}) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse(trips: [tripJson(status: status)]))
      ..on('/service-readiness', status: 200, body: readinessResponse());

    await pumpApp(tester);
    await waitForTrip(tester, (s) => s is! TripLoading);
    await settle(tester);
  }

  group('the stream follows the trip, not the screen', () {
    testWidgets('a running trip starts it', (tester) async {
      await openTrip(tester, status: 'RUNNING');

      expect(app.gps.state, isA<GpsAcquiring>());
      expect(find.byType(GpsStatusPill), findsOneWidget);
      expect(find.text('Finding position'), findsOneWidget);
    });

    testWidgets('a scheduled trip never starts it', (tester) async {
      await openTrip(tester, status: 'SCHEDULED');

      expect(app.gps.state, isA<GpsIdle>());
      expect(
        find.byType(GpsStatusPill),
        findsNothing,
        reason: 'a pill on a trip that has not started says nothing true',
      );
    });

    testWidgets('a closed trip never starts it', (tester) async {
      await openTrip(tester, status: 'COMPLETED');

      expect(app.gps.state, isA<GpsIdle>());
      expect(find.byType(GpsStatusPill), findsNothing);
    });
  });

  group('the pill', () {
    testWidgets('says its state in words, not only in colour', (tester) async {
      await openTrip(tester, status: 'RUNNING');

      final semantics = tester.widget<Semantics>(
        // The outermost: the pill's own node, not the one Text builds.
        find
            .descendant(
              of: find.byType(GpsStatusPill),
              matching: find.byType(Semantics),
            )
            .first,
      );

      expect(
        semantics.properties.label,
        'Position status: Finding position',
        reason: 'a driver using TalkBack gets nothing from a coloured dot',
      );
    });

    testWidgets('never blocks the trip with a dialog', (tester) async {
      await openTrip(tester, status: 'RUNNING');

      expect(find.byType(AlertDialog), findsNothing);
      expect(find.text('RUNNING'), findsOneWidget);
    });
  });

  group('permission', () {
    testWidgets('a refusal explains itself and leaves the trip alone',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.location.access = LocationAccess.deniedForever;
      app.backend.on(
        '/trips',
        status: 200,
        body: tripsResponse(trips: [tripJson(status: 'RUNNING')]),
      );

      await pumpApp(tester);
      await waitForTrip(tester, (s) => s is TripRunning);
      await waitForGps(tester, (s) => s is GpsDenied);
      await settle(tester);

      expect(find.text('This trip cannot be tracked'), findsOneWidget);
      expect(find.text('Open settings'), findsOneWidget);
      // E2 is a panel, not a route and not a dialog: the bus is still running.
      expect(find.byType(AlertDialog), findsNothing);
      expect(find.text('RUNNING'), findsOneWidget);
    });
  });
}
