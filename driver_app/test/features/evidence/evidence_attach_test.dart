import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/services/permission_service.dart';
import 'package:ctms_driver/core/widgets/evidence_card.dart';
import 'package:ctms_driver/features/inspection/domain/checklist.dart';
import 'package:ctms_driver/features/inspection/presentation/widgets/checklist_item_tile.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:ctms_driver/features/evidence/domain/evidence_state.dart';

import '../../helpers/evidence_fixtures.dart';
import '../../helpers/inspection_fixtures.dart';
import '../../helpers/test_harness.dart';
import '../../helpers/trip_fixtures.dart';

/// The slice's own done-when: a failing safety-critical item attaches a real
/// photograph, and the id reaches the draft that will cite it.
void main() {
  late TestApp app;

  tearDown(() async => app.dispose());

  Finder tileFor(String label) => find.ancestor(
        of: find.text(label),
        matching: find.byType(ChecklistItemTile),
      );

  /// Taps a control on the evidence card.
  ///
  /// Failing an item expands the tile with a note field and the card, which
  /// pushes these buttons below the fold on a test-sized viewport.
  Future<void> tapEvidence(WidgetTester tester, String label) async {
    await tester.ensureVisible(find.text(label));
    await settle(tester);
    await tester.tap(find.text(label));
    await settle(tester);
  }

  /// Opens the checklist and fails the brakes, which is what demands a photo.
  Future<void> failTheBrakes(WidgetTester tester) async {
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

    // The quick screen is the default now; the item list is what
    // "Something wrong?" opens.
    await tester.tap(find.text('This is correct'));
    await settle(tester);
    await tester.tap(find.text('Something wrong?'));
    await settle(tester);

    await tester.tap(
      find.descendant(of: tileFor('Brakes'), matching: find.text('Fail')),
    );
    await settle(tester);
  }

  testWidgets('a failed critical item asks for a photograph', (tester) async {
    await failTheBrakes(tester);

    expect(find.byType(EvidenceCard), findsOneWidget);
    expect(find.text('Take photograph'), findsOneWidget);
    expect(find.textContaining('A photograph is required'), findsOneWidget);
  });

  testWidgets('capture then confirm attaches, and the draft cites the id',
      (tester) async {
    await failTheBrakes(tester);
    app.backend
      ..on('/evidence/categories', status: 200, body: categoriesResponse())
      ..on('/evidence', status: 201, body: uploadResponse());

    await tapEvidence(tester, 'Take photograph');

    // Nothing has gone up yet — upload happens on confirm, not on capture.
    expect(find.text('Use photograph'), findsOneWidget);
    expect(app.backend.callsTo('/evidence'), 0);

    await tapEvidence(tester, 'Use photograph');
    await waitForEvidence(tester, (s) => s is! EvidenceUploading);
    await settle(tester);

    expect(find.text('Photograph attached'), findsOneWidget);

    final draft = inspectionStateOf(tester).draft!;
    expect(
      draft.answers['BRAKES']?.evidenceId,
      'evidence-1',
      reason: 'the id has to reach the draft, or a kill loses a photograph the '
          'driver already took',
    );
  });

  testWidgets('attaching clears the failed-incomplete state', (tester) async {
    await failTheBrakes(tester);
    app.backend
      ..on('/evidence/categories', status: 200, body: categoriesResponse())
      ..on('/evidence', status: 201, body: uploadResponse());

    final brakes =
        inspectionStateOf(tester).items.firstWhere((i) => i.code == 'BRAKES');

    expect(
      inspectionStateOf(tester).draft!.problemWith(brakes),
      AnswerProblem.notesMissing,
    );

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

    await tapEvidence(tester, 'Take photograph');
    await tapEvidence(tester, 'Use photograph');
    await waitForEvidence(tester, (s) => s is EvidenceUploaded);
    await settle(tester);

    expect(
      inspectionStateOf(tester).draft!.problemWith(brakes),
      isNull,
      reason: 'with a note and a photograph the item is finally complete',
    );
  });

  testWidgets('a refused photograph shows the server wording', (tester) async {
    await failTheBrakes(tester);
    app.backend
      ..on('/evidence/categories', status: 200, body: categoriesResponse())
      ..on('/evidence',
          status: 409,
          body: errorEnvelope('Photographs only (JPEG, PNG, HEIC, WebP).'));

    await tapEvidence(tester, 'Take photograph');
    await tapEvidence(tester, 'Use photograph');
    await waitForEvidence(tester, (s) => s is EvidenceRejected);
    await settle(tester);

    expect(
      find.text('Photographs only (JPEG, PNG, HEIC, WebP).'),
      findsOneWidget,
    );
    expect(find.text('Retake'), findsOneWidget);
    expect(
      inspectionStateOf(tester).draft!.answers['BRAKES']?.evidenceId,
      isNull,
    );
  });

  testWidgets('offline holds the photograph and says it is not sent',
      (tester) async {
    await failTheBrakes(tester);
    app.connectivity.emit(Reachability.offline);
    await settle(tester);

    await tapEvidence(tester, 'Take photograph');
    await tapEvidence(tester, 'Use photograph');
    await waitForEvidence(tester, (s) => s is EvidenceQueued);
    await settle(tester);

    expect(find.textContaining('not yet sent'), findsOneWidget);
    expect(find.textContaining('cannot be submitted'), findsOneWidget);
    expect(
      inspectionStateOf(tester).draft!.answers['BRAKES']?.evidenceId,
      isNull,
      reason: 'no id exists, so nothing may cite one',
    );
  });

  testWidgets('a denied camera says what cannot happen without it',
      (tester) async {
    await failTheBrakes(tester);
    app.permissions.answer = PermissionStatus.permanentlyDenied;

    await tapEvidence(tester, 'Take photograph');

    expect(find.textContaining('camera is switched off'), findsOneWidget);
    expect(
      find.textContaining('cannot be completed without a photograph'),
      findsOneWidget,
    );
    expect(find.text('Open settings'), findsOneWidget);
  });
}
