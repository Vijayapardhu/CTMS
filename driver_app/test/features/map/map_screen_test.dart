import 'package:ctms_driver/core/widgets/empty_state.dart';
import 'package:ctms_driver/features/gps/presentation/widgets/gps_status_pill.dart';
import 'package:ctms_driver/features/trip/domain/trip_state.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// R2.
///
/// The platform tile surface cannot render in a widget test, so what is
/// asserted is everything around it: whether a map is put on screen at all,
/// what is drawn on it, and whether the position and estimate are described
/// with the freshness the server gave them.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> openMap(
    WidgetTester tester, {
    String status = 'RUNNING',
    Map<String, dynamic>? position,
    String etaBasis = 'live',
    int? etaMinutes = 4,
    bool routeFails = false,
  }) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse(trips: [tripJson(status: status)]))
      ..on('/service-readiness', status: 200, body: readinessResponse());

    if (routeFails) {
      app.backend.offline('/routes/route-1/stops');
    } else {
      app.backend.on('/routes/route-1/stops', status: 200, body: routeStopsResponse());
    }

    for (var i = 0; i < 4; i++) {
      app.backend
        ..on('/live', status: 200, body: liveResponse(position: position))
        ..on('/eta',
            status: 200,
            body: etaResponse(basis: etaBasis, minutes: etaMinutes));
    }

    await pumpApp(tester);
    await waitForTrip(tester, (s) => s is! TripLoading);
    await settle(tester);
    await tester.tap(find.text('Map'));
    await settle(tester);
  }

  group('what gets drawn', () {
    testWidgets('no running trip shows no map at all', (tester) async {
      await openMap(tester, status: 'SCHEDULED');

      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.text('No trip is running'), findsOneWidget);
      expect(
        find.byType(GoogleMap),
        findsNothing,
        reason: 'a map with no bus on it reads as a bus parked somewhere it '
            'is not',
      );
    });

    testWidgets('a running trip renders the map, stops and route',
        (tester) async {
      await openMap(tester, position: livePosition());

      final map = tester.widget<GoogleMap>(find.byType(GoogleMap));

      expect(find.byType(GpsStatusPill), findsOneWidget);
      // Three stops from the server plus the bus.
      expect(map.markers, hasLength(4));
      expect(
        map.polylines.single.points,
        hasLength(3),
        reason: 'the route line is drawn through the stops CTMS supplied',
      );
    });

    testWidgets('no position yet means no bus marker', (tester) async {
      await openMap(tester);

      final map = tester.widget<GoogleMap>(find.byType(GoogleMap));

      expect(
        map.markers.where((m) => m.markerId.value == 'bus'),
        isEmpty,
        reason: 'a bus marker before any position is a guess',
      );
      expect(map.markers, hasLength(3));
    });
  });

  group('the next stop and the estimate', () {
    testWidgets('a live estimate is shown as a time', (tester) async {
      await openMap(tester, position: livePosition());

      expect(find.text('NEXT STOP'), findsOneWidget);
      expect(find.text('Stop 1'), findsWidgets);
      expect(find.text('4 min'), findsOneWidget);
    });

    testWidgets('a scheduled estimate is labelled as the timetable',
        (tester) async {
      await openMap(tester, etaBasis: 'scheduled', etaMinutes: 7);

      expect(
        find.text('7 min by timetable'),
        findsOneWidget,
        reason: 'a timetable estimate has nothing to do with where the bus is, '
            'and must not read like one that does',
      );
    });

    testWidgets('a stale estimate says it is not updating', (tester) async {
      await openMap(tester, position: livePosition(), etaBasis: 'stale');

      expect(find.textContaining('not updating'), findsWidgets);
    });

    testWidgets('an arrived stop says so', (tester) async {
      await openMap(tester, position: livePosition(), etaBasis: 'arrived');

      expect(find.text('Arrived'), findsOneWidget);
    });
  });

  group('honesty about the position', () {
    testWidgets('a stale position is faded and captioned', (tester) async {
      await openMap(
        tester,
        position: livePosition(isStale: true, ageSeconds: 360),
      );

      final map = tester.widget<GoogleMap>(find.byType(GoogleMap));
      final bus = map.markers.firstWhere((m) => m.markerId.value == 'bus');

      expect(bus.alpha, lessThan(1));
      expect(find.text('Position 6 minutes old'), findsOneWidget);
    });

    testWidgets('a fresh position carries no warning', (tester) async {
      await openMap(tester, position: livePosition());

      final map = tester.widget<GoogleMap>(find.byType(GoogleMap));
      final bus = map.markers.firstWhere((m) => m.markerId.value == 'bus');

      expect(bus.alpha, 1);
      expect(find.textContaining('outdated'), findsNothing);
      expect(find.textContaining('minutes old'), findsNothing);
    });

    testWidgets('a route that failed to load still shows the bus',
        (tester) async {
      await openMap(tester, position: livePosition(), routeFails: true);

      final map = tester.widget<GoogleMap>(find.byType(GoogleMap));

      expect(map.polylines, isEmpty);
      expect(map.markers.where((m) => m.markerId.value == 'bus'), hasLength(1));
      expect(find.textContaining('Route could not be loaded'), findsOneWidget);
    });
  });
}
