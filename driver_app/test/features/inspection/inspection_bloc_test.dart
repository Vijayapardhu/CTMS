import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/features/inspection/data/inspection_api.dart';
import 'package:ctms_driver/features/inspection/data/inspection_draft_store.dart';
import 'package:ctms_driver/features/inspection/domain/checklist.dart';
import 'package:ctms_driver/features/inspection/domain/inspection_state.dart';
import 'package:ctms_driver/features/inspection/presentation/bloc/inspection_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fake_backend.dart';
import '../../helpers/inspection_fixtures.dart';
import '../../helpers/test_doubles.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late FakeBackend backend;
  late SharedPreferences prefs;
  late InspectionDraftStore drafts;

  Future<InspectionBloc> build() async {
    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      retryDelays: const [],
    )..dio.httpClientAdapter = backend;

    return InspectionBloc(
      api: InspectionApi(client),
      drafts: drafts,
    );
  }

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    drafts = InspectionDraftStore(prefs, SilentLogger());
    backend = FakeBackend();
  });

  /// Answers every item so review becomes reachable.
  Future<void> completeAll(InspectionBloc bloc, {Verdict verdict = Verdict.passed}) async {
    bloc.add(const OdometerEntered(45200));
    await bloc.stream.first;

    for (final item in bloc.state.items) {
      bloc.add(ItemAnswered(item.code, verdict));
      await bloc.stream.first;

      if (verdict == Verdict.failed) {
        bloc.add(ItemNotesChanged(item.code, 'Pedal travel excessive.'));
        await bloc.stream.first;
      }
    }
  }

  group('opening', () {
    test('loads the server checklist — never a hard-coded one', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      final state = await bloc.stream.firstWhere((s) => s is! InspectionLoading);

      expect(state, isA<InspectionEditing>());
      expect(state.items, hasLength(14));
      expect(state.items.first.code, 'BRAKES');
      expect(state.items.first.safetyCritical, isTrue);
    });

    test('an empty checklist is a server fault, not an empty state', () async {
      backend.on('/inspections/checklist',
          status: 200, body: checklistResponse(items: []));
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      final state = await bloc.stream.firstWhere((s) => s is! InspectionLoading);

      expect(state, isA<InspectionUnavailable>());
      expect(
        (state as InspectionUnavailable).emptyChecklist,
        isTrue,
        reason: 'letting a driver complete a checklist with no items would '
            'clear a bus nobody inspected',
      );
    });

    test('a failed fetch leaves the inspection unstartable', () async {
      backend.offline('/inspections/checklist');
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      final state = await bloc.stream.firstWhere((s) => s is! InspectionLoading);

      expect(state, isA<InspectionUnavailable>());
    });
  });

  group('the draft survives', () {
    test('every change is written to storage', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);

      bloc.add(const OdometerEntered(45200));
      await bloc.stream.first;
      bloc.add(const ItemAnswered('BRAKES', Verdict.failed));
      await bloc.stream.first;

      final stored = drafts.read('bus-1');

      expect(stored, isNotNull);
      expect(stored!.odometer, 45200);
      expect(stored.answers['BRAKES']?.verdict, Verdict.failed);
    });

    test('a fresh bloc finds the checklist where the driver left it', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final first = await build();
      first.add(const InspectionOpened('bus-1'));
      await first.stream.firstWhere((s) => s is! InspectionLoading);
      first.add(const OdometerEntered(45200));
      await first.stream.first;
      first.add(const ItemAnswered('TYRES', Verdict.passed));
      await first.stream.first;
      await first.close();

      // A kill, a restart, a battery death. Same store, new everything else.
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final second = await build();
      addTearDown(second.close);

      second.add(const InspectionOpened('bus-1'));
      final state =
          await second.stream.firstWhere((s) => s is! InspectionLoading);

      expect(state.draft!.odometer, 45200);
      expect(state.draft!.answers['TYRES']?.verdict, Verdict.passed);
    });

    test('a draft belongs to one bus', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      bloc.add(const OdometerEntered(45200));
      await bloc.stream.first;

      expect(
        drafts.read('bus-2'),
        isNull,
        reason: 'a driver moved to another vehicle must not inherit the '
            'odometer recorded against the one they were on',
      );
    });

    test('discarding clears it', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      bloc.add(const OdometerEntered(45200));
      await bloc.stream.first;

      bloc.add(const DraftDiscarded());
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(drafts.read('bus-1'), isNull);
    });
  });

  group('completeness', () {
    test('an unanswered item blocks review', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);

      bloc.add(const ReviewRequested());
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(bloc.state, isA<InspectionEditing>());
    });

    test('a failure with no note is failed-incomplete', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      bloc.add(const ItemAnswered('CLEANLINESS', Verdict.failed));
      final state = await bloc.stream.first;

      final item = state.items.firstWhere((i) => i.code == 'CLEANLINESS');

      expect(state.draft!.problemWith(item), AnswerProblem.notesMissing);
    });

    test('a failed safety-critical item still needs a photograph', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      bloc.add(const ItemAnswered('BRAKES', Verdict.failed));
      await bloc.stream.first;
      bloc.add(const ItemNotesChanged('BRAKES', 'Pedal travel excessive.'));
      final state = await bloc.stream.first;

      final brakes = state.items.firstWhere((i) => i.code == 'BRAKES');

      expect(
        state.draft!.problemWith(brakes),
        AnswerProblem.evidenceMissing,
        reason: 'this is what stops a driver failing the brakes and walking '
            'away',
      );
    });

    test('a complete checklist reaches review', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      await completeAll(bloc);

      bloc.add(const ReviewRequested());
      final state = await bloc.stream.first;

      expect(state, isA<InspectionReviewing>());
    });

    test('switching a verdict keeps the note already typed', () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      bloc.add(const ItemAnswered('HORN', Verdict.failed));
      await bloc.stream.first;
      bloc.add(const ItemNotesChanged('HORN', 'Very faint.'));
      await bloc.stream.first;
      bloc.add(const ItemAnswered('HORN', Verdict.passed));
      await bloc.stream.first;
      bloc.add(const ItemAnswered('HORN', Verdict.failed));
      final state = await bloc.stream.first;

      expect(
        state.draft!.answers['HORN']?.notes,
        'Very faint.',
        reason: 'a mis-tap should not cost the driver what they already typed',
      );
    });

    test('filling the untouched remainder leaves answered items alone',
        () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      bloc.add(const ItemAnswered('HORN', Verdict.failed));
      await bloc.stream.first;
      bloc.add(const ItemNotesChanged('HORN', 'Very faint.'));
      await bloc.stream.first;

      // Changing the verdict back re-runs the remainder fill over an item that
      // is now PASSED. Filling by verdict rather than by presence would replace
      // this answer wholesale and take the note with it.
      bloc.add(const ItemAnswered('HORN', Verdict.passed));
      final state = await bloc.stream.first;

      expect(state.draft!.answers['HORN']?.notes, 'Very faint.');
    });
  });

  group('submitting', () {
    Future<InspectionBloc> readyToSubmit({Verdict verdict = Verdict.passed}) async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      await completeAll(bloc, verdict: verdict);
      bloc.add(const ReviewRequested());
      await bloc.stream.first;

      return bloc;
    }

    test('sends every item, in the checklist order', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections', status: 201, body: submissionResponse());
      bloc.add(const SubmissionRequested());
      await bloc.stream.firstWhere((s) => s is InspectionSubmitted);

      final sent = backend.requests.last;
      final body = sent.body as Map<String, dynamic>;

      expect(sent.method, 'POST');
      expect(sent.path, contains('/buses/bus-1/inspections'));
      expect(
        (body['items'] as List),
        hasLength(14),
        reason: 'a partial submission is a 422; every item goes',
      );
      expect(body['odometer_reading'], 45200);
    });

    test('renders the server outcome and never predicts it', () async {
      final bloc = await readyToSubmit(verdict: Verdict.passed);
      addTearDown(bloc.close);

      // Every item passed, yet the server says otherwise. The app must show
      // what it was told.
      backend.on('/inspections',
          status: 201,
          body: submissionResponse(outcome: 'PASSED_WITH_DEFECTS', ticket: 'ticket-1'));

      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is InspectionSubmitted);

      final result = (state as InspectionSubmitted).result;
      expect(result.outcome, InspectionOutcome.passedWithDefects);
      expect(result.openedTicket, isTrue);
    });

    test('a FAILED outcome is carried through', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections',
          status: 201, body: submissionResponse(outcome: 'FAILED'));
      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is InspectionSubmitted);

      expect((state as InspectionSubmitted).result.outcome, InspectionOutcome.failed);
      expect(state.result.outcome.clearsTheBus, isFalse);
    });

    test('the draft is cleared only once the server accepted it', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections', status: 201, body: submissionResponse());
      bloc.add(const SubmissionRequested());
      await bloc.stream.firstWhere((s) => s is InspectionSubmitted);

      expect(drafts.read('bus-1'), isNull);
    });

    test('a 500 keeps the draft and stays on review', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections', status: 500, body: errorEnvelope('Server error.'));
      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is! InspectionSubmitting);

      expect(state, isA<InspectionReviewing>());
      expect(
        drafts.read('bus-1'),
        isNotNull,
        reason: 'a draft cleared before acceptance is a draft lost to a 500',
      );
    });

    test('a 409 sends the driver back to the odometer', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections',
          status: 409,
          body: errorEnvelope('The reading must be at least 45 108 km.'));
      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is! InspectionSubmitting);

      expect(state, isA<InspectionEditing>());
      expect((state as InspectionEditing).target, RejectionTarget.odometer);
      expect(
        state.rejection!.message,
        'The reading must be at least 45 108 km.',
        reason: 'the backend writes these for drivers; never paraphrase a 409',
      );
    });

    test('a 409 is never retried on the driver\'s behalf', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections', status: 409, body: errorEnvelope('No.'));
      bloc.add(const SubmissionRequested());
      await bloc.stream.firstWhere((s) => s is! InspectionSubmitting);

      expect(backend.callsTo('/inspections'), 1);
    });

    test('a 422 naming an item lands on that item', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections',
          status: 422,
          body: errorEnvelope(
            'Please check what you entered.',
            errors: {
              'items.0.evidence_id': ['A photograph is required.'],
            },
          ));

      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is! InspectionSubmitting);

      expect(state, isA<InspectionEditing>());
      expect((state as InspectionEditing).target, RejectionTarget.item);
      expect(state.rejectedItem, 'BRAKES');
    });

    test('a 422 with no item lands on the checklist', () async {
      final bloc = await readyToSubmit();
      addTearDown(bloc.close);

      backend.on('/inspections',
          status: 422,
          body: errorEnvelope('The checklist is incomplete.', errors: {}));

      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is! InspectionSubmitting);

      expect((state as InspectionEditing).target, RejectionTarget.checklist);
    });
  });

  group('offline', () {
    test('a network failure mid-submit saves rather than losing the work',
        () async {
      backend.on('/inspections/checklist', status: 200, body: checklistResponse());
      final bloc = await build();
      addTearDown(bloc.close);

      bloc.add(const InspectionOpened('bus-1'));
      await bloc.stream.firstWhere((s) => s is! InspectionLoading);
      await completeAll(bloc);
      bloc.add(const ReviewRequested());
      await bloc.stream.first;

      backend.offline('/inspections');
      bloc.add(const SubmissionRequested());
      final state = await bloc.stream.firstWhere((s) => s is! InspectionSubmitting);

      expect(state, isA<InspectionSaved>());
      expect(drafts.read('bus-1'), isNotNull);
    });
  });
}
