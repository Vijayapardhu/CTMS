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
  const InspectionOpened(this.busId, {this.minimumOdometer});

  final String busId;

  /// The bus's recorded total. The reading must be at least this (BR-061), and
  /// the driver is told the minimum *before* they get it wrong.
  final int? minimumOdometer;

  @override
  List<Object?> get props => [busId, minimumOdometer];
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

  Future<void> _onOpened(
    InspectionOpened event,
    Emitter<InspectionState> emit,
  ) async {
    minimumOdometer = event.minimumOdometer;
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

    await _persist(emit, current, current.value.copyWith(answers: answers));
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

    if (current is InspectionReviewing) {
      emit(InspectionEditing(
        value: current.value,
        checklist: current.checklist,
      ));
    } else if (current is InspectionSaved) {
      emit(InspectionEditing(
        value: current.value,
        checklist: current.checklist,
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
          rejection: failure,
          rejectedItem: code,
          target: RejectionTarget.item,
        );
      }

      return InspectionEditing(
        value: current.value,
        checklist: current.checklist,
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
    emit(InspectionEditing(value: next, checklist: current.checklist));
  }
}
