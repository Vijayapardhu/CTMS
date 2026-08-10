import 'package:ctms_driver/features/operations/domain/stop_proximity.dart';
import 'package:ctms_driver/features/tracking/domain/live_trip.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// Road distance against straight-line distance.
///
/// These are two different measurements answering two different questions, and
/// the app got into trouble by using one of them for both. The straight line
/// between Velangi and Aditya University is 24.9 km; the road is 37 km. The
/// first is the right answer to "is the bus at this stop"; the second is the
/// right answer to "how far is left to drive". Neither substitutes.
void main() {
  group('the server\'s road distance is what a driver reads', () {
    Eta etaWith({int? metres, bool? estimate}) => Eta(
          basis: EtaBasis.live,
          minutes: 12,
          distanceMetres: metres,
          distanceIsEstimate: estimate,
        );

    test('metres below a kilometre are shown whole', () {
      expect(etaWith(metres: 850, estimate: false).readableDistance, '850 m');
    });

    test('single-figure kilometres keep one decimal', () {
      expect(etaWith(metres: 7400, estimate: false).readableDistance, '7.4 km');
    });

    test('a decimal at thirty-seven kilometres is noise, so it is dropped', () {
      expect(etaWith(metres: 36964, estimate: false).readableDistance, '37 km');
    });

    test('an estimate is marked, never presented as exact', () {
      // The offline provider's answer for the same journey. It is useful and
      // it is not the road, and the driver is entitled to know which.
      expect(etaWith(metres: 32414, estimate: true).readableDistance, '~32 km');
    });

    test('no distance reported reads as no distance, not as zero', () {
      expect(etaWith(metres: null, estimate: null).readableDistance, isNull);
    });

    test('both fields survive the wire', () {
      final eta = Eta.fromJson({
        'basis': 'live',
        'minutes': 12,
        'stops_away': 2,
        'distance_metres': 36964,
        'distance_is_estimate': false,
      });

      expect(eta.distanceMetres, 36964);
      expect(eta.distanceIsEstimate, isFalse);
      expect(eta.readableDistance, '37 km');
    });

    test('a scheduled estimate carries no distance at all', () {
      final eta = Eta.fromJson({
        'basis': 'scheduled',
        'minutes': null,
        'stops_away': 3,
        'distance_metres': null,
        'distance_is_estimate': null,
      });

      expect(eta.readableDistance, isNull);
    });
  });

  group('proximity stays straight-line, and stays local', () {
    test('the Velangi run measures 24.9 km as the crow flies', () {
      final near = StopProximity.between(
        fromLatitude: 16.8696848,
        fromLongitude: 82.1142335,
        toLatitude: 17.0893372,
        toLongitude: 82.0670772,
      );

      // Google's road distance for the same pair is 36,964 m. If this ever
      // starts returning that, the geofence has been wired to the routing
      // provider and a bus 100 m from a stop by road will be judged to have
      // arrived from the wrong side of a river.
      expect(near.metres, closeTo(24934, 50));
      expect(near.withinRadius, isFalse);
    });

    test('a bus inside the radius may record an arrival', () {
      // Roughly 60 m north.
      final near = StopProximity.between(
        fromLatitude: 17.0893372,
        fromLongitude: 82.0670772,
        toLatitude: 17.0898772,
        toLongitude: 82.0670772,
      );

      expect(near.metres, lessThan(StopProximity.radiusMetres));
      expect(near.withinRadius, isTrue);
    });
  });

  group('on screen', () {
    late TestApp app;

    tearDown(() async => app.dispose());

    Future<void> openRunningTrip(
      WidgetTester tester, {
      required Map<String, dynamic> eta,
    }) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips',
            status: 200,
            body: tripsResponse(trips: [tripJson(status: 'RUNNING')]))
        ..on('/service-readiness', status: 200, body: readinessResponse())
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse());

      for (var i = 0; i < 4; i++) {
        app.backend
          ..on('/live', status: 200, body: liveResponse(position: livePosition()))
          ..on('/eta', status: 200, body: eta);
      }

      await pumpApp(tester);
      await settle(tester);
    }

    testWidgets('the road distance is shown, not the straight line',
        (tester) async {
      await openRunningTrip(tester, eta: etaResponse());

      expect(find.textContaining('37 km to'), findsOneWidget);
      expect(
        find.textContaining('24.9 km'),
        findsNothing,
        reason: 'the straight line is for the geofence; showing it beside a '
            'stop name reads as the journey and is 12 km short',
      );
    });

    testWidgets('an offline estimate is marked on screen', (tester) async {
      await openRunningTrip(
        tester,
        eta: etaResponse(distanceMetres: 32414, distanceIsEstimate: true),
      );

      expect(find.textContaining('~32 km to'), findsOneWidget);
    });

    testWidgets('no distance from the server means no distance on screen',
        (tester) async {
      await openRunningTrip(
        tester,
        eta: etaResponse(
          basis: 'scheduled',
          minutes: null,
          distanceMetres: null,
          distanceIsEstimate: null,
        ),
      );

      expect(find.textContaining(' km to'), findsNothing);
      expect(find.textContaining(' m to'), findsNothing);
    });
  });
}
