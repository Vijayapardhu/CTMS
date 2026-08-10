import 'package:ctms_driver/core/widgets/empty_state.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// The `/notifications` envelope, in the shape the API actually returns.
Map<String, dynamic> alertsResponse({List<Map<String, dynamic>>? items}) {
  final data = items ??
      [
        {
          'id': 'n-1',
          'event_key': 'incident.sos.raised',
          'category': 'INCIDENT',
          'priority': 'CRITICAL',
          'title': 'Emergency (SOS) — KA-80-IB-1764',
          'body': 'Emergency (SOS) Respond immediately.',
          'data': <String, dynamic>{},
          'read_at': null,
          'created_at': '2026-08-10T01:15:21.000000Z',
        },
      ];

  return {
    'success': true,
    'message': 'Notifications retrieved successfully.',
    'code': 200,
    'data': data,
    'pagination': {
      'total': data.length,
      'per_page': 20,
      'current_page': 1,
      'last_page': 1,
    },
  };
}

Map<String, dynamic> unreadResponse(int unread) => {
      'success': true,
      'message': 'Unread count retrieved successfully.',
      'code': 200,
      'data': {'unread': unread},
    };

/// R3.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Future<void> openAlerts(WidgetTester tester) async {
    app.backend
      ..on('/trips', status: 200, body: tripsResponse())
      ..on('/service-readiness', status: 200, body: readinessResponse());

    await pumpApp(tester);
    await settle(tester);
    await tester.tap(find.text('Alerts'));
    await settle(tester);
  }

  testWidgets('the office\'s own words are shown, not rewritten',
      (tester) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/notifications/unread-count', status: 200, body: unreadResponse(1))
      ..on('/notifications', status: 200, body: alertsResponse());

    await openAlerts(tester);

    expect(find.text('Emergency (SOS) — KA-80-IB-1764'), findsOneWidget);
    expect(find.text('Emergency (SOS) Respond immediately.'), findsOneWidget);
    expect(find.text('NEW'), findsOneWidget);
  });

  testWidgets('nothing from the office is a calm empty state, not an error',
      (tester) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/notifications/unread-count', status: 200, body: unreadResponse(0))
      ..on('/notifications', status: 200, body: alertsResponse(items: []));

    await openAlerts(tester);

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('Nothing from the office'), findsOneWidget);
    expect(find.textContaining('error'), findsNothing);
  });

  testWidgets('a read alert carries no NEW marker', (tester) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/notifications/unread-count', status: 200, body: unreadResponse(0))
      ..on('/notifications', status: 200, body: alertsResponse(items: [
        {
          'id': 'n-2',
          'event_key': 'trip.started',
          'category': 'TRIP',
          'priority': 'NORMAL',
          'title': 'Trip started',
          'body': 'Your trip is being tracked.',
          'data': <String, dynamic>{},
          'read_at': '2026-08-10T01:20:00.000000Z',
          'created_at': '2026-08-10T01:15:21.000000Z',
        },
      ]));

    await openAlerts(tester);

    expect(find.text('Trip started'), findsOneWidget);
    expect(find.text('NEW'), findsNothing);
    expect(find.text('Mark all read'), findsNothing);
  });
}
