import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/widgets/consequence_panel.dart';
import 'package:ctms_driver/core/icons/app_icons.dart';
import 'package:ctms_driver/core/widgets/dual_action_selector.dart';
import 'package:ctms_driver/core/widgets/skeleton_loader.dart';
import 'package:ctms_driver/features/inspection/domain/checklist.dart';
import 'package:ctms_driver/features/inspection/domain/inspection_state.dart';
import 'package:ctms_driver/features/inspection/presentation/inspection_screen.dart';
import 'package:ctms_driver/features/inspection/presentation/widgets/checklist_item_tile.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/inspection_fixtures.dart';
import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// P9, P10 and P11 through the real app.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  /// Signs in on a blocked trip and opens the inspection.
  Future<void> openInspection(WidgetTester tester) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse())
      ..on('/service-readiness',
          status: 200,
          body: readinessResponse(cleared: false, reasons: [missingInspection]))
      ..on('/inspections/checklist', status: 200, body: checklistResponse());

    await pumpApp(tester);

    await tester.tap(find.text('Start inspection'));
    await settle(tester);
  }

  /// The fourteen labels, in the order the fixture returns them. Tiles are
  /// found by label rather than by index: the list builds lazily, so the i-th
  /// built tile is not the i-th item.
  const labels = [
    'Brakes', 'Tyres and pressure', 'Lights and indicators', 'Steering',
    'Doors', 'Emergency exit', 'Fire extinguisher', 'First aid kit',
    'Mirrors', 'Horn', 'Wipers', 'Fluid levels', 'Fuel level', 'Cleanliness',
  ];

  Future<void> reveal(WidgetTester tester, String label) =>
      tester.scrollUntilVisible(
        find.text(label),
        300,
        scrollable: find
            .descendant(
              of: find.byType(InspectionScreen),
              matching: find.byType(Scrollable),
            )
            .first,
      );

  Finder tileFor(String label) => find.ancestor(
        of: find.text(label),
        matching: find.byType(ChecklistItemTile),
      );

  /// Answers every tile, so review unlocks.
  Future<void> completeChecklist(WidgetTester tester, {String? fail}) async {
    await tester.enterText(find.byType(TextFormField).first, '45200');
    await settle(tester);

    for (final label in labels) {
      await reveal(tester, label);
      await settle(tester);

      final verdict = label == fail ? 'Fail' : 'Pass';
      await tester.tap(
        find.descendant(of: tileFor(label), matching: find.text(verdict)),
      );
      await settle(tester);

      if (label == fail) {
        await tester.enterText(
          find.descendant(
            of: tileFor(label),
            matching: find.byType(TextFormField),
          ),
          'Pedal travel excessive.',
        );
        await settle(tester);
      }
    }
  }

  group('P9 — the checklist', () {
    testWidgets('R1 blocked offers the one action the driver owns',
        (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(cleared: false, reasons: [missingInspection]));

      await pumpApp(tester);

      expect(find.text('Start inspection'), findsOneWidget);
    });

    testWidgets('it is not offered when nothing is actionable', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(cleared: false, reasons: [expiredInsurance]));

      await pumpApp(tester);

      expect(
        find.text('Start inspection'),
        findsNothing,
        reason: 'a driver who does the inspection and is still blocked by the '
            'insurance has been sent on an errand',
      );
    });

    testWidgets('renders every server item, and no more', (tester) async {
      await openInspection(tester);

      // The list builds lazily, so what is asserted is that the tiles render
      // and that the bloc holds every item the server sent.
      expect(find.byType(ChecklistItemTile), findsWidgets);
      expect(find.text('Brakes'), findsOneWidget);
      expect(inspectionStateOf(tester).items, hasLength(14));

      await reveal(tester, 'Cleanliness');
      expect(find.text('Cleanliness'), findsOneWidget);
    });

    testWidgets('loading shows a skeleton', (tester) async {
      app = await registerTestDependencies(signedIn: true);
      app.backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(cleared: false, reasons: [missingInspection]))
        ..on('/inspections/checklist',
            status: 200,
            body: checklistResponse(),
            delay: const Duration(seconds: 5));

      await pumpApp(tester);
      await tester.tap(find.text('Start inspection'));
      await tester.pump();
      await tester.pump();

      expect(find.byType(SkeletonLoader), findsOneWidget);

      // Let the fetch land so the shimmer stops before teardown.
      await waitForInspection(tester, (s) => s is! InspectionLoading);
      await settle(tester);
    });

    testWidgets('neither verdict is pre-selected', (tester) async {
      await openInspection(tester);

      final selectors = tester.widgetList<DualActionSelector<Verdict>>(
        find.byType(DualActionSelector<Verdict>),
      );

      expect(selectors, isNotEmpty);
      expect(
        selectors.every((s) => s.value == null),
        isTrue,
        reason: 'a checklist that starts with everything passing is a '
            'checklist nobody read',
      );
    });

    testWidgets('the minimum odometer is stated before any error',
        (tester) async {
      await openInspection(tester);

      expect(
        find.textContaining('Must be at least'),
        findsOneWidget,
        reason: 'telling a driver the rule after they break it wastes a trip '
            'to the dashboard',
      );
    });

    testWidgets('review is blocked and says how many are left', (tester) async {
      await openInspection(tester);
      await tester.enterText(find.byType(TextFormField).first, '45200');
      await settle(tester);

      expect(find.textContaining('14 left'), findsOneWidget);
      expect(
        tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Review (14 left)')).onPressed,
        isNull,
      );
    });

    testWidgets('failing an item asks what was found', (tester) async {
      await openInspection(tester);

      await tester.tap(
        find.descendant(of: tileFor('Brakes'), matching: find.text('Fail')),
      );
      await settle(tester);

      expect(
        find.descendant(
          of: tileFor('Brakes'),
          matching: find.byType(TextFormField),
        ),
        findsOneWidget,
      );
    });

    testWidgets('a failed safety-critical item says a photograph is required',
        (tester) async {
      await openInspection(tester);

      await tester.tap(
        find.descendant(of: tileFor('Brakes'), matching: find.text('Fail')),
      );
      await settle(tester);

      expect(find.textContaining('A photograph is required'), findsOneWidget);
    });

    testWidgets('a safety-critical item is marked even while passing',
        (tester) async {
      await openInspection(tester);

      expect(
        find.byWidgetPredicate(
          (w) => w is AppIconView && w.icon == AppIcon.safetyCritical,
        ),
        findsWidgets,
        reason: 'a driver should know which items ground the bus before they '
            'decide, not after',
      );
    });
  });

  group('P10 — review', () {
    testWidgets('a clean checklist reaches review', (tester) async {
      await openInspection(tester);
      await completeChecklist(tester);

      await tester.tap(find.text('Review'));
      await settle(tester);

      expect(find.text('Review and submit'), findsOneWidget);
      expect(find.textContaining('Odometer: 45200'), findsOneWidget);
      expect(find.text('Nothing failed'), findsOneWidget);
    });

    testWidgets('a failed safety-critical item cannot reach review yet',
        (tester) async {
      await openInspection(tester);
      await completeChecklist(tester, fail: 'Brakes');

      // Correct, and the reason the build order says this slice is only half
      // testable alone: a critical failure needs a photograph, and capture is
      // the evidence slice. Review stays shut until it exists.
      expect(find.text('Review'), findsNothing);
      expect(find.textContaining('1 left'), findsOneWidget);

      // The list is at the bottom by now, so scroll back to the item that is
      // holding it up.
      await tester.scrollUntilVisible(
        find.text('Brakes'),
        -300,
        scrollable: find
            .descendant(
              of: find.byType(InspectionScreen),
              matching: find.byType(Scrollable),
            )
            .first,
      );
      await settle(tester);

      expect(find.textContaining('A photograph is required'), findsOneWidget);
    });

    testWidgets('a non-critical failure does not threaten to ground the bus',
        (tester) async {
      await openInspection(tester);
      await completeChecklist(tester, fail: 'Cleanliness');

      await tester.tap(find.text('Review'));
      await settle(tester);

      expect(find.byType(ConsequencePanel), findsNothing);
      expect(find.text('1 item failed'), findsOneWidget);
    });

    testWidgets('back returns to the checklist with the answers intact',
        (tester) async {
      await openInspection(tester);
      await completeChecklist(tester);
      await tester.tap(find.text('Review'));
      await settle(tester);

      await tester.tap(find.text('Back to checklist'));
      await settle(tester);

      expect(find.byType(ChecklistItemTile), findsWidgets);
      expect(find.text('Review'), findsOneWidget);
    });
  });

  group('P11 — the result', () {
    testWidgets('renders the outcome the server decided', (tester) async {
      await openInspection(tester);
      await completeChecklist(tester);
      await tester.tap(find.text('Review'));
      await settle(tester);

      app.backend.on('/inspections',
          status: 201,
          body: submissionResponse(
            outcome: 'PASSED_WITH_DEFECTS',
            ticket: 'ticket-1',
          ));

      await tester.tap(find.text('Submit inspection'));
      await waitForInspection(tester, (s) => s is InspectionSubmitted);
      await settle(tester);

      expect(
        find.text('Passed with defects'),
        findsOneWidget,
        reason: 'every item passed, and the app must still show what it was '
            'told rather than what it expected',
      );
      expect(find.textContaining('maintenance ticket has been opened'),
          findsOneWidget);
    });

    testWidgets('a FAILED outcome says the bus is out of service',
        (tester) async {
      await openInspection(tester);
      // A non-critical failure, so review is reachable. The outcome is the
      // server's regardless — which is the point being checked.
      await completeChecklist(tester, fail: 'Cleanliness');
      await tester.tap(find.text('Review'));
      await settle(tester);

      app.backend.on('/inspections',
          status: 201, body: submissionResponse(outcome: 'FAILED'));

      await tester.tap(find.text('Submit inspection'));
      await waitForInspection(tester, (s) => s is InspectionSubmitted);
      await settle(tester);

      expect(find.text('Bus out of service'), findsOneWidget);
    });
  });

  group('refusals', () {
    testWidgets('a 409 puts the driver back on the odometer, verbatim',
        (tester) async {
      await openInspection(tester);
      await completeChecklist(tester);
      await tester.tap(find.text('Review'));
      await settle(tester);

      app.backend.on('/inspections',
          status: 409,
          body: errorEnvelope('The reading must be at least 45 108 km.'));

      await tester.tap(find.text('Submit inspection'));
      await waitForInspection(tester, (s) => s is InspectionEditing);
      await settle(tester);

      expect(
        find.text('The reading must be at least 45 108 km.'),
        findsWidgets,
        reason: 'never paraphrase a 409',
      );
      expect(find.byType(ChecklistItemTile), findsWidgets);
    });

    testWidgets('offline saves and says the bus is not cleared',
        (tester) async {
      await openInspection(tester);
      await completeChecklist(tester);
      await tester.tap(find.text('Review'));
      await settle(tester);

      app.connectivity.emit(Reachability.offline);
      await settle(tester);

      await tester.tap(find.text('Submit inspection'));
      await waitForInspection(tester, (s) => s is InspectionSaved);
      await settle(tester);

      expect(find.textContaining('not yet submitted'), findsOneWidget);
      expect(
        find.textContaining('bus is not cleared'),
        findsOneWidget,
        reason: 'queueing it silently would tell a driver the bus is on its '
            'way to being cleared when it is not',
      );
      expect(app.backend.callsTo('/inspections'), 0);
    });
  });

  group('read-only boundary', () {
    testWidgets('nothing here starts or ends a trip', (tester) async {
      await openInspection(tester);

      for (final label in ['START TRIP', 'Start trip', 'Board', 'SOS']) {
        expect(find.text(label), findsNothing);
      }
    });
  });
}
