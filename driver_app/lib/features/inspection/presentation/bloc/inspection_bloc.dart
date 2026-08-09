import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/connectivity/connectivity_service.dart';
import '../../data/inspection_api.dart';
import '../../data/inspection_draft_store.dart';
import '../../domain/checklist.dart';
import '../../domain/inspection_state.dart';

sealed class InspectionEvent extends Equatable {
  const InspectionEvent();

  @override
  List<Object?> get props => const [];
}

/// Open the checklist for a bus, restoring any draft.
final class InspectionOpened extends InspectionEvent {
  const InspectionOpened(this.busId, {this.minimumOdometer, this.busLabel});

  final String busId;

  /// The registration, so the screen can say which bus this is about.
  final String? busLabel;

  /// The bus's recorded total. The reading must be at least this (BR-061), and
  /// the driver is told the minimum *before* they get it wrong.
  final int? minimumOdometer;

  @override
  List<Object?> get props => [busId, minimumOdometer, busLabel];
}

final class OdometerEntered extends InspectionEvent {
  const OdometerEntered(this.value);

  final int? value;

  @override
  List<Object?> get props => [value];
}

final class ItemAnswered extends InspectionEvent {
  const ItemAnswered(this.code, this.verdict);

  final String code;
  final Verdict verdict;

  @override
  List<Object?> get props => [code, verdict];
}

final class ItemNotesChanged extends InspectionEvent {
  const ItemNotesChanged(this.code, this.notes);

  final String code;
  final String notes;

  @override
  List<Object?> get props => [code, notes];
}

/// A photograph was accepted by the server, or withdrawn.
///
/// Carries the id the submission will cite. Passing null clears it, which is
/// what happens when a driver changes a failing verdict back to a pass.
final class ItemEvidenceChanged extends InspectionEvent {
  const ItemEvidenceChanged(this.code, this.evidenceId);

  final String code;
  final String? evidenceId;

  @override
  List<Object?> get props => [code, evidenceId];
}

/// "I have checked the bus and everything listed is OK."
///
/// One deliberate act standing for PASS on every item the server supplied.
/// Not a default and not reachable by inertia — see Phase 6 §3.
final class AllOkDeclared extends InspectionEvent {
  const AllOkDeclared();
}

/// "Something wrong?" — open the server's list so one item can be singled out.
final class ChecklistRevealed extends InspectionEvent {
  const ChecklistRevealed();
}

/// All items answered — move to review.
final class ReviewRequested extends InspectionEvent {
  const ReviewRequested();
}

/// Back from review to the checklist.
final class EditingResumed extends InspectionEvent {
  const EditingResumed();
}

final class SubmissionRequested extends InspectionEvent {
  const SubmissionRequested();
}

/// Throw the draft away, after the driver confirmed.
final class DraftDiscarded extends InspectionEvent {
  const DraftDiscarded();
}

/// M2.
///
/// Scoped to the inspection flow rather than the app: unlike the session or the
/// trip, an inspection belongs to the screens the driver is standing in.
class InspectionBloc extends Bloc<InspectionEvent, InspectionState> {
  InspectionBloc({
    required InspectionApi api,
    required InspectionDraftStore drafts,
    required ConnectivityService connectivity,
  })  : _api = api,
        _drafts = drafts,
        _connectivity = connectivity,
        super(const InspectionLoading()) {
    on<InspectionOpened>(_onOpened);
    on<OdometerEntered>(_onOdometer);
    on<ItemAnswered>(_onAnswered);
    on<ItemNotesChanged>(_onNotes);
    on<ItemEvidenceChanged>(_onEvidence);
    on<AllOkDeclared>(_onAllOk);
    on<ChecklistRevealed>(_onReveal);
    on<ReviewRequested>(_onReview);
    on<EditingResumed>(_onResume);
    on<SubmissionRequested>(_onSubmit);
    on<DraftDiscarded>(_onDiscard);
  }

  final InspectionApi _api;
  final InspectionDraftStore _drafts;
  final ConnectivityService _connectivity;

  /// The floor for the odometer, from the bus's recorded total.
  int? minimumOdometer;

  /// The registration the driver identifies the bus by.
  String busLabel = '';

  Future<void> _onOpened(
    InspectionOpened event,
    Emitter<InspectionState> emit,
  ) async {
    minimumOdometer = event.minimumOdometer;
    busLabel = event.busLabel ?? '';
    emit(const InspectionLoading());

    try {
      final checklist = await _api.checklist();

      if (checklist.isEmpty) {
        // Not an empty state. A checklist with no items means the server is
        // misconfigured, and letting the driver "complete" it would clear a
        // bus nobody inspected.
        emit(const InspectionUnavailable(
          ServerFailure('The checklist came back empty.'),
          emptyChecklist: true,
        ));
        return;
      }

      final restored = _drafts.read(event.busId) ??
          InspectionDraft(busId: event.busId);

      emit(InspectionEditing(value: restored, checklist: checklist));
    } on ApiFailure catch (e) {
      emit(InspectionUnavailable(e));
    }
  }

  Future<void> _onOdometer(
    OdometerEntered event,
    Emitter<InspectionState> emit,
  ) async {
    final current = state;
    if (current is! InspectionEditing) return;

    await _persist(
      emit,
      current,
      current.value.copyWith(odometer: event.value),
    );
  }

  Future<void> _onAnswered(
    ItemAnswered event,
    Emitter<InspectionState> emit,
  ) async {
    final current = state;
    if (current is! InspectionEditing) return;

    final answers = Map<String, ItemAnswer>.from(current.value.answers);
    final existing = answers[event.code];

    answers[event.code] = existing == null
        ? ItemAnswer(verdict: event.verdict)
        // Keep whatever was already written. A driver who fails an item,
        // types a note, taps Pass by mistake and taps Fail again should not
        // have to type it a second time.
        : existing.copyWith(verdict: event.verdict);

    // Singling one item out is itself the statement that the rest are fine.
    // A driver who opens the list to report the brakes has checked the bus;
    // making them then tap Pass thirteen times is the friction this screen
    // exists to remove. The remainder is filled in, and the screen says so.
    final next = current.value
        .copyWith(answers: answers)
        .allOk(current.checklist);

    await _persist(emit, current, next);
  }

  Future<void> _onNotes(
    ItemNotesChanged event,
    Emitter<InspectionState> emit,
  ) async {
    final current = state;
    if (current is! InspectionEditing) return;

    final answers = Map<String, ItemAnswer>.from(current.value.answers);
    final existing = answers[event.code];
    if (existing == null) return;

    answers[event.code] = ItemAnswer(
      verdict: existing.verdict,
      notes: event.notes,
      evidenceId: existing.evidenceId,
    );

    await _persist(emit, current, current.value.copyWith(answers: answers));
  }

  Future<void> _onEvidence(
    ItemEvidenceChanged event,
    Emitter<InspectionState> emit,
  ) async {
    final current = state;
    if (current is! InspectionEditing) return;

    final answers = Map<String, ItemAnswer>.from(current.value.answers);
    final existing = answers[event.code];
    if (existing == null) return;

    answers[event.code] = ItemAnswer(
      verdict: existing.verdict,
      notes: existing.notes,
      evidenceId: event.evidenceId,
    );

    await _persist(emit, current, current.value.copyWith(answers: answers));
  }

  Future<void> _onAllOk(
    AllOkDeclared event,
    Emitter<InspectionState> emit,
  ) async {
    final current = state;
    if (current is! InspectionEditing) return;

    // Every item the server sent, whatever that number is today.
    final next = current.value.allOk(current.checklist);
    await _drafts.save(next);

    // One deliberate action, one event. Emitting the review here rather than
    // asking the screen to follow up with `ReviewRequested`: handlers for
    // different event types run concurrently, so a second event would be
    // evaluated against the draft as it was *before* this one finished
    // writing, and the review would refuse an inspection that is complete.
    emit(InspectionReviewing(value: next, checklist: current.checklist));
  }

  void _onReveal(ChecklistRevealed event, Emitter<InspectionState> emit) {
    final current = state;
    if (current is! InspectionEditing) return;

    emit(InspectionEditing(
      value: current.value,
      checklist: current.checklist,
      revealed: true,
      rejection: current.rejection,
      rejectedItem: current.rejectedItem,
      target: current.target,
    ));
  }

  void _onReview(ReviewRequested event, Emitter<InspectionState> emit) {
    final current = state;
    if (current is! InspectionEditing) return;

    // Guarded rather than trusted: the button is disabled until the checklist
    // is complete, but the guard is what makes that true.
    if (current.value.unresolved(current.checklist).isNotEmpty) return;

    emit(InspectionReviewing(
      value: current.value,
      checklist: current.checklist,
    ));
  }

  void _onResume(EditingResumed event, Emitter<InspectionState> emit) {
    final current = state;

    // Back to whichever mode the driver actually needs: the list only when
    // something is wrong with it.
    if (current is InspectionReviewing) {
      emit(InspectionEditing(
        value: current.value,
        checklist: current.checklist,
        revealed: current.value.failures(current.checklist).isNotEmpty,
      ));
    } else if (current is InspectionSaved) {
      emit(InspectionEditing(
        value: current.value,
        checklist: current.checklist,
        revealed: current.value.failures(current.checklist).isNotEmpty,
      ));
    }
  }

  Future<void> _onSubmit(
    SubmissionRequested event,
    Emitter<InspectionState> emit,
  ) async {
    final current = state;
    if (current is! InspectionReviewing) return;

    // An inspection cannot be submitted offline: a safety-critical failure
    // needs an evidence upload, and there is no honest way to queue a
    // clearance. The draft is kept and the driver is told plainly.
    if (_connectivity.current == Reachability.offline) {
      emit(InspectionSaved(value: current.value, checklist: current.checklist));
      return;
    }

    emit(InspectionSubmitting(
      value: current.value,
      checklist: current.checklist,
    ));

    try {
      final result = await _api.submit(
        current.value.busId,
        current.value.toSubmission(current.checklist),
      );

      // Only now. A draft cleared before the server accepted it is a draft
      // lost to a 500.
      await _drafts.clear(current.value.busId);

      emit(InspectionSubmitted(result));
    } on NetworkFailure {
      emit(InspectionSaved(value: current.value, checklist: current.checklist));
    } on ApiFailure catch (e) {
      emit(_rejected(current, e));
    }
  }

  Future<void> _onDiscard(
    DraftDiscarded event,
    Emitter<InspectionState> emit,
  ) async {
    final busId = state.draft?.busId;
    if (busId != null) await _drafts.clear(busId);
  }

  /// Puts the driver back on the exact thing the server refused.
  InspectionState _rejected(InspectionReviewing current, ApiFailure failure) {
    // A 409 on this endpoint is the odometer rule (BR-061) — a reading below
    // the bus's recorded total. It is a refusal, never a thing to retry.
    if (failure is ConflictFailure) {
      return InspectionEditing(
        value: current.value,
        checklist: current.checklist,
        rejection: failure,
        target: RejectionTarget.odometer,
      );
    }

    if (failure is ValidationFailure) {
      final field = failure.fieldErrors.keys.firstWhere(
        (k) => k.startsWith('items.'),
        orElse: () => '',
      );

      if (field.isNotEmpty) {
        // `items.3.evidence_id` — the index is into the list that was sent,
        // which is the checklist in order.
        final index = int.tryParse(field.split('.').elementAtOrNull(1) ?? '');
        final code = index != null && index < current.checklist.length
            ? current.checklist[index].code
            : null;

        return InspectionEditing(
          value: current.value,
          checklist: current.checklist,
          // The server named an item, so the driver has to be able to see it.
          revealed: true,
          rejection: failure,
          rejectedItem: code,
          target: RejectionTarget.item,
        );
      }

      return InspectionEditing(
        value: current.value,
        checklist: current.checklist,
        revealed: true,
        rejection: failure,
        target: RejectionTarget.checklist,
      );
    }

    // 5xx and anything else: stay on review so the driver can retry without
    // walking the checklist again. The draft is untouched.
    return InspectionReviewing(
      value: current.value,
      checklist: current.checklist,
    );
  }

  /// Writes the draft before the state changes, so a kill between the two
  /// loses at most nothing.
  Future<void> _persist(
    Emitter<InspectionState> emit,
    InspectionEditing current,
    InspectionDraft next,
  ) async {
    await _drafts.save(next);
    emit(InspectionEditing(
      value: next,
      checklist: current.checklist,
      revealed: current.revealed,
    ));
  }
}
