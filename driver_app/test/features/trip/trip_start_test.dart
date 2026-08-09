import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/widgets/confirm_sheet.dart';
import 'package:ctms_driver/core/widgets/reason_list.dart';
import 'package:ctms_driver/features/trip/domain/trip_state.dart';
import 'package:ctms_driver/features/trip/presentation/trip_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// Slice 6 — J6, through the real router, bloc and API client.
///
/// The refusals are the substance here. Every one of them is a safety rule
/// declining in the server's own words, and the failure this file exists to
/// catch is any of them being flattened into "something went wrong".
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  /// Boots signed in on a cleared trip, ready to start.
  Future<void> openReadyTrip(WidgetTester tester) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse())
      ..on('/service-readiness', status: 200, body: readinessResponse());

    await pumpApp(tester);
    await waitForTrip(tester, (s) => s is! TripLoading);
    await settle(tester);
  }

  /// Taps START TRIP and confirms S2.
  Future<void> confirmStart(WidgetTester tester) async {
    await tester.tap(find.text('START TRIP'));
    await settle(tester);
    await tester.tap(find.text('Start trip'));
    await settle(tester);
  }

  group('S2', () {
    testWidgets('ready offers START TRIP', (tester) async {
      await openReadyTrip(tester);

      expect(app.trip.state, isA<TripReady>());
      expect(find.text('START TRIP'), findsOneWidget);
    });

    testWidgets('nothing is sent until the driver confirms', (tester) async {
      await openReadyTrip(tester);

      await tester.tap(find.text('START TRIP'));
      await settle(tester);

      expect(find.byType(ConfirmSheet), findsOneWidget);
      expect(
        app.backend.callsTo('/start'),
        0,
        reason: 'opening the sheet is not agreeing to it',
      );
    });

    testWidgets('dismissing the sheet starts nothing', (tester) async {
      await openReadyTrip(tester);

      await tester.tap(find.text('START TRIP'));
      await settle(tester);
      await tester.tap(find.text('Not yet'));
      await settle(tester);

      expect(app.backend.callsTo('/start'), 0);
      expect(app.trip.state, isA<TripReady>());
    });

    testWidgets('the sheet names the bus and the departure', (tester) async {
      await openReadyTrip(tester);

      await tester.tap(find.text('START TRIP'));
      await settle(tester);

      expect(
        find.descendant(
          of: find.byType(ConfirmSheet),
          matching: find.text('KA-05-MJ-3391'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byType(ConfirmSheet),
          matching: find.textContaining('Departs 08:00'),
        ),
        findsOneWidget,
      );
    });
  });

  group('starting', () {
    testWidgets('a confirmed start posts once and the trip runs',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.on('/start', status: 200, body: startResponse());

      await confirmStart(tester);

      expect(app.backend.callsTo('/start'), 1);
      expect(app.trip.state, isA<TripRunning>());
      expect(find.text('RUNNING'), findsOneWidget);
    });

    testWidgets('the running trip is the server row, not a local guess',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 200,
        body: startResponse(
          trip: tripJson(status: 'RUNNING', occupied: 7, booked: 32),
        ),
      );

      await confirmStart(tester);

      // The occupancy the server returned, in the counter Slice 8 added.
      expect(find.text('ON BOARD'), findsOneWidget);
      expect(find.text('7'), findsOneWidget);
    });
  });

  group('refusals', () {
    testWidgets('a 409 with reasons[] renders every one of them',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 409,
        body: notClearedRefusal([missingInspection, expiredInsurance]),
      );

      await confirmStart(tester);

      expect(app.trip.state, isA<TripBlocked>());
      expect(find.byType(ReasonList), findsOneWidget);
      // Both, not the first. A driver who fixes one and finds the next on the
      // following round trip is the failure the array exists to prevent.
      expect(find.text(missingInspection), findsOneWidget);
      expect(find.text(expiredInsurance), findsOneWidget);
    });

    testWidgets('reasons stay grouped by what the driver can act on',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 409,
        body: notClearedRefusal([missingInspection, expiredInsurance]),
      );

      await confirmStart(tester);

      expect(find.text('You can fix this'), findsOneWidget);
      expect(find.text('Operations must fix this'), findsOneWidget);
      // The actionable reason keeps its way out.
      expect(find.text('Start inspection'), findsOneWidget);
    });

    testWidgets('a single-reason refusal is shown verbatim, not paraphrased',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 409,
        body: startRefusal(message: "This driver's licence has expired."),
      );

      await confirmStart(tester);

      expect(app.trip.state, isA<TripBlocked>());
      expect(find.text("This driver's licence has expired."), findsOneWidget);
      // Only operations can fix a licence, so no inspection button.
      expect(find.text('Start inspection'), findsNothing);
    });

    testWidgets('being early is waiting, not blocked', (tester) async {
      await openReadyTrip(tester);
      app.backend.on('/start', status: 409, body: tooEarlyRefusal());

      await confirmStart(tester);

      expect(app.trip.state, isA<TripWaiting>());
      expect(
        find.text('This trip cannot start until 07:45.'),
        findsOneWidget,
        reason: 'the server names the time; the client must not recompute it',
      );

      // Let the recheck timer run out rather than leaving it armed at teardown.
      await tester.pump(const Duration(seconds: 31));
    });

    testWidgets('waiting disables Start and resolves itself', (tester) async {
      await openReadyTrip(tester);
      app.backend.on('/start', status: 409, body: tooEarlyRefusal());

      await confirmStart(tester);

      final button =
          tester.widget<ButtonStyleButton>(find.byKey(startTripKey));
      expect(button.onPressed, isNull);
      expect(find.textContaining('unlock by itself'), findsOneWidget);

      // The window length is server configuration, so the client asks again
      // rather than counting down to a moment it cannot know.
      await tester.pump(const Duration(seconds: 31));
      await settle(tester);

      expect(app.trip.state, isA<TripReady>());
    });

    testWidgets('a 403 leaves the trip startable and says why', (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 403,
        body: {
          'success': false,
          'message': 'This trip is not yours to operate.',
          'data': null,
          'errors': null,
          'code': 403,
        },
      );

      await confirmStart(tester);

      // Not a business refusal: the trip is unchanged, so the state is too.
      expect(app.trip.state, isA<TripReady>());
      expect(find.text('This trip is not yours to operate.'), findsOneWidget);
    });

    testWidgets('a 429 is shown as the server worded it', (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 429,
        body: {
          'success': false,
          'message': 'Too many requests.',
          'data': null,
          'errors': null,
          'code': 429,
        },
      );

      await confirmStart(tester);

      expect(app.trip.state, isA<TripReady>());
      expect(find.text('Too many requests.'), findsOneWidget);
    });

    testWidgets('a 500 keeps the trip and offers the button again',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.on(
        '/start',
        status: 500,
        body: {
          'success': false,
          'message': 'Something went wrong on our side.',
          'data': null,
          'errors': null,
          'code': 500,
        },
      );

      await confirmStart(tester);

      final state = app.trip.state;
      expect(state, isA<TripReady>());
      expect((state as TripReady).starting, isFalse);
      expect(find.text('START TRIP'), findsOneWidget);
    });

    testWidgets('a dead connection never claims the trip started',
        (tester) async {
      await openReadyTrip(tester);
      app.backend.offline('/start');

      await confirmStart(tester);

      expect(
        app.trip.state,
        isA<TripReady>(),
        reason: 'a trip is running only when the server says it is',
      );
      expect(find.text('RUNNING'), findsNothing);
    });
  });

  group('offline', () {
    testWidgets('Start is disabled with no connection', (tester) async {
      await openReadyTrip(tester);

      app.connectivity.emit(Reachability.offline);
      await settle(tester);

      final button =
          tester.widget<ButtonStyleButton>(find.byKey(startTripKey));

      expect(button.onPressed, isNull);
      expect(find.textContaining('need a connection'), findsOneWidget);
    });
  });

  group('accessibility', () {
    testWidgets('START TRIP is a 64dp target', (tester) async {
      await openReadyTrip(tester);

      final size = tester.getSize(find.byKey(startTripKey));

      expect(size.height, greaterThanOrEqualTo(64));
    });
  });
}
