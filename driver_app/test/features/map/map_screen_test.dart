import 'package:ctms_driver/core/widgets/empty_state.dart';
import 'package:ctms_driver/features/gps/domain/gps_state.dart';
import 'package:ctms_driver/features/gps/presentation/widgets/gps_status_pill.dart';
import 'package:ctms_driver/features/map/presentation/map_screen.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// R2.
///
/// The platform map view cannot be rendered by a widget test, so what is
/// asserted here is everything around it: whether a map is put on screen at
/// all, and whether the position it would draw is described honestly.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> openMap(WidgetTester tester, {required String status}) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse(trips: [tripJson(status: status)]))
      ..on('/service-readiness', status: 200, body: readinessResponse());

    await pumpApp(tester);
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
      reason: 'a map centred on a default city with no marker reads as a bus '
          'parked somewhere it is not',
    );
  });

  testWidgets('a running trip puts a map and the GPS state on screen',
      (tester) async {
    await openMap(tester, status: 'RUNNING');

    expect(find.byType(GoogleMap), findsOneWidget);
    expect(find.byType(GpsStatusPill), findsOneWidget);
  });

  testWidgets('with no fix yet there is no marker to place', (tester) async {
    await openMap(tester, status: 'RUNNING');

    final map = tester.widget<GoogleMap>(find.byType(GoogleMap));

    expect(app.gps.state, isA<GpsAcquiring>());
    expect(
      map.markers,
      isEmpty,
      reason: 'a marker before the first fix would be a guess',
    );
  });
}
