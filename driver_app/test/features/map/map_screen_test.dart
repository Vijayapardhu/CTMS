import 'package:ctms_driver/core/widgets/empty_state.dart';
import 'package:ctms_driver/features/trip/domain/trip_state.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// R2, rendered.
///
/// Kept to the two questions only a real tree can answer — is a map put on
/// screen, and is the next stop legible beside it. What is *drawn* on the map is
/// decided by pure functions with their own file: a `GoogleMap` is an Android
/// platform view, and rendering several in one test file makes the binding
/// throw on the previous one's disposal.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> openMap(WidgetTester tester, {required String status}) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse(trips: [tripJson(status: status)]))
      ..on('/service-readiness', status: 200, body: readinessResponse())
      ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse());

    for (var i = 0; i < 4; i++) {
      app.backend
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse(minutes: 4));
    }

    await pumpApp(tester);
    await waitForTrip(tester, (s) => s is! TripLoading);
    await settle(tester);
    await tester.tap(find.text('Map'));
    await settle(tester);
  }

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

  testWidgets('a running trip renders the map with the next stop and estimate',
      (tester) async {
    await openMap(tester, status: 'RUNNING');

    expect(find.byType(GoogleMap), findsOneWidget);
    expect(find.text('NEXT STOP'), findsOneWidget);
    expect(find.text('Stop 1'), findsWidgets);
    // The estimate is rendered as h:mm:ss, from the server's own `eta_at`.
    expect(find.textContaining(RegExp(r'\d+:\d{2}:\d{2}')), findsOneWidget);
  });
}
