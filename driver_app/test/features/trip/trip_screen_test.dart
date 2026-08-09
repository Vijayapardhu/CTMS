import 'package:ctms_driver/core/widgets/empty_state.dart';
import 'package:ctms_driver/core/widgets/reason_list.dart';
import 'package:ctms_driver/core/widgets/skeleton_loader.dart';
import 'package:ctms_driver/core/widgets/status_chip.dart';
import 'package:ctms_driver/features/trip/domain/trip_state.dart';
import 'package:ctms_driver/features/trip/presentation/trip_screen.dart';
import 'package:ctms_driver/features/trip/presentation/widgets/trip_card.dart';
import 'package:ctms_driver/app/app.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// R1 through the real app: real router, real bloc, real API client, with the
/// fake adapter standing in for the socket.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  /// Boots signed in and settles the trip tab on whatever the backend says.
  Future<void> openTrip(WidgetTester tester) async {
    await pumpApp(tester);
    await waitForTrip(tester, (s) => s is! TripLoading);
    await settle(tester);
  }

  group('every M1 state renders', () {
    testWidgets('loading shows a skeleton, never a bare spinner',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      // Nothing scripted for /trips, so the read never resolves during this
      // test and the loading state stays on screen to be inspected.
      await tester.pumpWidget(const CtmsDriverApp());
      await waitForSession(tester, (s) => s.isAuthenticated);
      await tester.pump();

      expect(find.byType(SkeletonLoader), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(TripScreen),
          matching: find.byType(CircularProgressIndicator),
        ),
        findsNothing,
        reason: 'the layout is known in advance, so a skeleton says what is '
            'coming where a spinner says only "wait"',
      );

      // Let the read finish before the test ends. The shimmer repeats forever
      // by design, and a test that tears down with one on screen waits for an
      // animation that is never going to stop.
      await waitForTrip(tester, (s) => s is! TripLoading);
      await settle(tester);
    });

    testWidgets('none shows the calm empty state', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend.on('/trips', status: 200, body: tripsResponse(trips: []));

      await openTrip(tester);

      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.text('No trip assigned today'), findsOneWidget);
      expect(
        find.textContaining('error', findRichText: true),
        findsNothing,
        reason: 'an empty day is not a failure',
      );
    });

    testWidgets('ready shows the bus, the route and the figures',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await openTrip(tester);

      expect(find.byType(TripCard), findsOneWidget);
      expect(find.text('KA-05-MJ-3391'), findsOneWidget);
      expect(find.textContaining('RT-5167'), findsOneWidget);
      expect(find.text('READY'), findsOneWidget);
      expect(find.text('Departs 08:00'), findsOneWidget);
      expect(find.text('14 stops'), findsOneWidget);
      expect(find.text('32 students expected'), findsOneWidget);
    });

    testWidgets('blocked shows every reason, grouped', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(
              cleared: false,
              reasons: [expiredInsurance, missingInspection],
            ));

      await openTrip(tester);

      expect(find.text('NOT READY'), findsOneWidget);
      expect(find.byType(ReasonList), findsOneWidget);
      expect(find.text(missingInspection), findsOneWidget);
      expect(
        find.text(expiredInsurance),
        findsOneWidget,
        reason: 'showing only the first reason sends a driver round the loop '
            'twice',
      );
      expect(find.text('You can fix this'), findsOneWidget);
      expect(find.text('Operations must fix this'), findsOneWidget);
    });

    testWidgets('running shows occupancy from the trip payload',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend.on('/trips',
          status: 200,
          body: tripsResponse(trips: [tripJson(status: 'RUNNING', occupied: 26)]));

      await openTrip(tester);

      expect(find.text('RUNNING'), findsOneWidget);
      expect(find.text('26 of 40 on board'), findsOneWidget);
    });

    testWidgets('closed shows the server cancellation reason verbatim',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend.on('/trips',
          status: 200,
          body: tripsResponse(trips: [
            tripJson(
              status: 'CANCELLED',
              cancellationReason: 'Merged into the 08:10 service.',
              autoClosed: true,
            )
          ]));

      await openTrip(tester);

      expect(find.text('CANCELLED'), findsOneWidget);
      expect(
        find.textContaining('Merged into the 08:10 service.'),
        findsOneWidget,
        reason: 'a driver whose trip vanished deserves the reason, not a '
            'paraphrase',
      );
      expect(find.textContaining('closed automatically'), findsOneWidget);
    });

    testWidgets('unavailable is not the empty state', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend.on('/trips', status: 500, body: {
        'success': false,
        'message': 'Something went wrong on our side.',
        'data': null,
      });

      await openTrip(tester);

      expect(find.text('Could not load today\'s trip'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      expect(
        find.byType(EmptyState),
        findsNothing,
        reason: 'claiming "no trip assigned" when the server never answered '
            'tells a driver they have no work',
      );
    });
  });

  group('recovering', () {
    testWidgets('the retry button loads the trip', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend.offline('/trips');

      await openTrip(tester);
      expect(find.text('Try again'), findsOneWidget);

      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await tester.tap(find.text('Try again'));
      await waitForTrip(tester, (s) => s is TripReady);
      await settle(tester);

      expect(find.text('KA-05-MJ-3391'), findsOneWidget);
      expect(find.text('Try again'), findsNothing);
    });

    testWidgets('a failed refresh keeps the trip and says it is old',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await openTrip(tester);
      expect(find.text('KA-05-MJ-3391'), findsOneWidget);

      app.backend.offline('/trips');
      await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
      await waitForTrip(tester, (s) => s.stale);
      await settle(tester);

      expect(
        find.text('KA-05-MJ-3391'),
        findsOneWidget,
        reason: 'the day\'s work must not leave the screen for the length of '
            'a tunnel',
      );
      expect(find.textContaining('could not be refreshed'), findsOneWidget);
    });
  });

  group('read-only', () {
    testWidgets('offers no control that changes a trip', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await openTrip(tester);

      // Every mutation belongs to a later slice. A control rendered here
      // without its flow behind it is a button that lies.
      for (final label in [
        'START TRIP',
        'Start trip',
        'Start inspection',
        'Complete trip',
        'End trip',
        'Board',
        'Alight',
        'Report a problem',
        'SOS',
      ]) {
        expect(find.text(label), findsNothing, reason: '"$label" is not Slice 3');
      }
    });

    testWidgets('makes no write request while the screen is open',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await openTrip(tester);

      expect(
        app.backend.requests.map((r) => r.method).toSet(),
        {'GET'},
        reason: 'Slice 3 reads; it does not write',
      );
    });
  });

  group('accessibility', () {
    testWidgets('the status chip reads its meaning, not its colour',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(cleared: false, reasons: [missingInspection]));

      await openTrip(tester);

      expect(
        find.bySemanticsLabel(RegExp('NOT READY, blocked')),
        findsOneWidget,
        reason: 'a driver with deuteranopia must tell blocked from ready '
            'without seeing hue',
      );
    });

    testWidgets('the chip carries an icon as well as a colour', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await openTrip(tester);

      final chip = tester.widget<StatusChip>(find.byType(StatusChip));
      expect(chip.icon, isNotNull);
    });
  });
}
