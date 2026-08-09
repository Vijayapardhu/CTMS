import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/widgets/consequence_panel.dart';
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

/// P9, P10 and P11 under the exception-driven model.
///
/// The normal check is one deliberate tap. The long list exists, is
/// server-driven, and is only reached by a driver who has something to report.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Finder tileFor(String label) => find.ancestor(
        of: find.text(label),
        matching: find.byType(ChecklistItemTile),
      );

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

  /// Signs in on a blocked trip and opens the inspection.
  Future<void> openInspection(
    WidgetTester tester, {
    List<Map<String, dynamic>>? items,
  }) async {
    app = await registerTestDependencies(signedIn: true);
    app.backend
      ..on('/trips', status: 200, body: tripsResponse())
      ..on('/service-readiness',
          status: 200,
          body: readinessResponse(cleared: false, reasons: [missingInspection]))
      ..on('/inspections/checklist',
          status: 200, body: checklistResponse(items: items));

    await pumpApp(tester);
    await tester.tap(find.text('Start inspection'));
    await settle(tester);
  }

  /// Accepts the odometer the bus already reported. No typing.
  Future<void> acceptOdometer(WidgetTester tester) async {
    await tester.tap(find.text('This is correct'));
    await settle(tester);
  }

  /// Opens the server list so one item can be singled out.
  Future<void> somethingWrong(WidgetTester tester) async {
    await tester.tap(find.text('Something wrong?'));
    await settle(tester);
  }

  group('P9 — the quick check', () {
    testWidgets('opens on one action, not fourteen', (tester) async {
      await openInspection(tester);

      expect(find.text('ALL OK'), findsOneWidget);
      expect(find.text('Have you checked the bus?'), findsOneWidget);
      expect(
        find.byType(ChecklistItemTile),
        findsNothing,
        reason: 'the normal answer to a pre-trip check is "the bus is fine", '
            'and it should cost one tap',
      );
    });

    testWidgets('offers the recorded odometer rather than demanding typing',
        (tester) async {
      await openInspection(tester);

      // 45120 is the bus fixture's own current_odometer.
      expect(find.text('45120 km'), findsOneWidget);
      expect(find.text('This is correct'), findsOneWidget);
      expect(find.text('Edit'), findsOneWidget);
    });

    testWidgets('nothing is selected on open', (tester) async {
      await openInspection(tester);

      expect(
        inspectionStateOf(tester).draft!.answers,
        isEmpty,
        reason: 'ALL OK is an affirmative act, not a default the driver can '
            'submit through by inertia',
      );
      expect(inspectionStateOf(tester).draft!.odometer, isNull);
    });

    testWidgets('ALL OK is held back until the odometer is settled',
        (tester) async {
      await openInspection(tester);

      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      expect(inspectionStateOf(tester).draft!.answers, isEmpty);
      expect(find.text('Enter the odometer reading'), findsOneWidget);
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

      await waitForInspection(tester, (s) => s is! InspectionLoading);
      await settle(tester);
    });
  });

  group('ALL OK', () {
    testWidgets('marks every server item passed and reaches review',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      final state = inspectionStateOf(tester);
      expect(state, isA<InspectionReviewing>());
      expect(state.draft!.passedCount(state.items), 14);
      expect(find.text('14 of 14 checks OK'), findsOneWidget);
    });

    testWidgets('counts whatever the server sent, never fourteen',
        (tester) async {
      await openInspection(tester, items: [
        {'item': 'BRAKES', 'label': 'Brakes', 'safety_critical': true},
        {'item': 'HORN', 'label': 'Horn', 'safety_critical': false},
        {'item': 'WIPERS', 'label': 'Wipers', 'safety_critical': false},
      ]);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      expect(find.text('3 of 3 checks OK'), findsOneWidget);
      expect(inspectionStateOf(tester).draft!.answers, hasLength(3));
    });

    testWidgets('a fifteenth item needs no release', (tester) async {
      final items = [
        for (var i = 0; i < 15; i++)
          {'item': 'ITEM_$i', 'label': 'Check $i', 'safety_critical': false},
      ];

      await openInspection(tester, items: items);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      expect(find.text('15 of 15 checks OK'), findsOneWidget);
    });

    testWidgets('submits every item the server supplied', (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      app.backend.on('/inspections', status: 201, body: submissionResponse());
      await tester.tap(find.text('Confirm & submit'));
      await waitForInspection(tester, (s) => s is InspectionSubmitted);
      await settle(tester);

      final body = app.backend.requests.last.body as Map<String, dynamic>;
      final sent = body['items'] as List;

      expect(sent, hasLength(14));
      expect(
        sent.every((i) => (i as Map)['passed'] == true),
        isTrue,
        reason: 'the backend still receives the complete inspection record',
      );
      expect(find.text('Cleared'), findsOneWidget);
    });
  });

  group('the exception path', () {
    testWidgets('"Something wrong?" reveals the server list', (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await somethingWrong(tester);

      expect(find.text('What is wrong?'), findsOneWidget);
      expect(find.byType(ChecklistItemTile), findsWidgets);
      expect(inspectionStateOf(tester).items, hasLength(14));
    });

    testWidgets('a singled-out item takes a verdict with no default',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await somethingWrong(tester);

      final selectors = tester.widgetList<DualActionSelector<Verdict>>(
        find.byType(DualActionSelector<Verdict>),
      );

      expect(selectors, isNotEmpty);
      expect(selectors.every((s) => s.value == null), isTrue);
    });

    testWidgets('failing one item leaves the rest alone', (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await somethingWrong(tester);
      await reveal(tester, 'Horn');

      await tester.tap(
        find.descendant(of: tileFor('Horn'), matching: find.text('Fail')),
      );
      await settle(tester);

      final state = inspectionStateOf(tester);
      expect(state.draft!.answers['HORN']?.verdict, Verdict.failed);
      expect(
        state.draft!.passedCount(state.items),
        13,
        reason: 'a driver reporting the horn should not have to confirm '
            'thirteen things that are fine — singling one item out is the '
            'statement that the rest are',
      );
      // Only the reported item is outstanding — it still owes its note.
      expect(state.draft!.unresolved(state.items), hasLength(1));
      expect(state.draft!.unresolved(state.items).single.code, 'HORN');
    });

    testWidgets('reporting one problem does not demand the other thirteen',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await somethingWrong(tester);

      await tester.tap(
        find.descendant(of: tileFor('Brakes'), matching: find.text('Fail')),
      );
      await settle(tester);

      final state = inspectionStateOf(tester);

      // Brakes is safety-critical, so it still owes a note and a photograph —
      // but nothing else is outstanding.
      expect(state.draft!.unresolved(state.items), hasLength(1));
      expect(state.draft!.passedCount(state.items), 13);
    });

    testWidgets('a later failure overrides an earlier ALL OK', (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      // Back out of review, then report the brakes.
      await tester.tap(find.text('Go back'));
      await settle(tester);
      await somethingWrong(tester);

      await tester.tap(
        find.descendant(of: tileFor('Brakes'), matching: find.text('Fail')),
      );
      await settle(tester);

      final state = inspectionStateOf(tester);
      expect(state.draft!.answers['BRAKES']?.verdict, Verdict.failed);
      expect(
        state.draft!.passedCount(state.items),
        13,
        reason: 'the explicit, later, more serious act wins',
      );
    });

    testWidgets('a safety-critical failure still demands a photograph',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await somethingWrong(tester);

      await tester.tap(
        find.descendant(of: tileFor('Brakes'), matching: find.text('Fail')),
      );
      await settle(tester);

      expect(find.textContaining('A photograph is required'), findsOneWidget);

      final brakes =
          inspectionStateOf(tester).items.firstWhere((i) => i.code == 'BRAKES');
      await tester.enterText(
        find.descendant(
          of: tileFor('Brakes'),
          matching: find.byType(TextFormField),
        ),
        'Pedal travel excessive.',
      );
      await settle(tester);

      expect(
        inspectionStateOf(tester).draft!.problemWith(brakes),
        AnswerProblem.evidenceMissing,
      );
    });
  });

  group('P10 — review', () {
    testWidgets('a clean check shows a count, not fourteen rows',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      expect(find.text('Inspection ready'), findsOneWidget);
      expect(find.text('14 of 14 checks OK'), findsOneWidget);
      expect(find.byType(ConsequencePanel), findsNothing);
      expect(find.byType(ChecklistItemTile), findsNothing);
    });

    testWidgets('a non-critical failure shows only what is wrong',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await somethingWrong(tester);

      await reveal(tester, 'Cleanliness');
      await tester.tap(
        find.descendant(of: tileFor('Cleanliness'), matching: find.text('Fail')),
      );
      await settle(tester);
      await tester.enterText(
        find.descendant(
          of: tileFor('Cleanliness'),
          matching: find.byType(TextFormField),
        ),
        'Rubbish left under the seats.',
      );
      await settle(tester);

      final state = inspectionStateOf(tester);
      final cleanliness =
          state.items.firstWhere((i) => i.code == 'CLEANLINESS');

      // Complete: a non-critical failure needs a note and nothing else.
      expect(state.draft!.problemWith(cleanliness), isNull);
      expect(state.draft!.failures(state.items), hasLength(1));
      expect(
        state.draft!.groundsTheBus(state.items),
        isFalse,
        reason: 'cleanliness is not safety-critical, so nothing threatens to '
            'take the bus off the road',
      );
    });
  });

  group('refusals', () {
    testWidgets('a 409 is shown verbatim and never retried', (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      app.backend.on('/inspections',
          status: 409,
          body: errorEnvelope('The reading must be at least 45 108 km.'));

      await tester.tap(find.text('Confirm & submit'));
      await waitForInspection(tester, (s) => s is InspectionEditing);
      await settle(tester);

      expect(
        find.text('The reading must be at least 45 108 km.'),
        findsWidgets,
      );
      expect(app.backend.callsTo('/inspections'), 1);
    });

    testWidgets('offline saves and says the bus is not cleared',
        (tester) async {
      await openInspection(tester);
      await acceptOdometer(tester);
      await tester.tap(find.text('ALL OK'));
      await settle(tester);

      // The submit is attempted rather than pre-empted — the call is the only
      // evidence of reachability worth having — so the transport has to be
      // genuinely down for this to be the offline path.
      app.connectivity.emit(Reachability.offline);
      app.backend.offline('/inspections');
      await settle(tester);

      await tester.tap(find.text('Confirm & submit'));
      await waitForInspection(tester, (s) => s is InspectionSaved);
      await settle(tester);

      expect(find.textContaining('not yet submitted'), findsOneWidget);
      expect(find.textContaining('bus is not cleared'), findsOneWidget);
    });
  });

  group('accessibility', () {
    testWidgets('ALL OK says what it does, not what colour it is',
        (tester) async {
      await openInspection(tester);

      expect(
        find.bySemanticsLabel('All OK. Marks every check as passed.'),
        findsOneWidget,
      );
    });

    testWidgets('ALL OK is a large target', (tester) async {
      await openInspection(tester);

      expect(
        tester.getSize(find.text('ALL OK')).height,
        greaterThan(0),
      );
      expect(
        tester.getSize(find.byType(FilledButton).first).height,
        greaterThanOrEqualTo(48),
      );
    });
  });
}
