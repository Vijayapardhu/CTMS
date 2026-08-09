import '../../../core/api/api_failure.dart';
import 'checklist.dart';

/// Where a rejection lands, so the screen can put the driver on the exact
/// field the server refused rather than on a general apology.
enum RejectionTarget { odometer, item, checklist, none }

/// M2 — the inspection.
///
/// `capturing` is absent on purpose: evidence capture is its own machine and
/// its own slice. Slice 4 records that a photograph is required and blocks
/// review until one exists; it does not take the picture.
sealed class InspectionState {
  const InspectionState();

  InspectionDraft? get draft => null;
  List<ChecklistItem> get items => const [];
}

/// Fetching the checklist, with nothing to show yet.
final class InspectionLoading extends InspectionState {
  const InspectionLoading();
}

/// The checklist could not be fetched, so the inspection cannot start.
///
/// Distinct from an empty checklist, which is a server fault rather than a
/// transport one.
final class InspectionUnavailable extends InspectionState {
  const InspectionUnavailable(this.reason, {this.emptyChecklist = false});

  final ApiFailure reason;

  /// The server answered with no items at all. Nothing the driver can do.
  final bool emptyChecklist;
}

/// Filling it in. Holds the draft; persisted on every change.
final class InspectionEditing extends InspectionState {
  const InspectionEditing({
    required this.value,
    required this.checklist,
    this.rejection,
    this.rejectedItem,
    this.target = RejectionTarget.none,
  });

  final InspectionDraft value;
  final List<ChecklistItem> checklist;

  /// Carried back from a refused submission, so the message the driver sees is
  /// the server's own rather than a paraphrase.
  final ApiFailure? rejection;

  /// Which item the server named, when it named one.
  final String? rejectedItem;

  final RejectionTarget target;

  @override
  InspectionDraft? get draft => value;

  @override
  List<ChecklistItem> get items => checklist;
}

/// Every item answered. Shows the consequence before anything irreversible.
final class InspectionReviewing extends InspectionState {
  const InspectionReviewing({required this.value, required this.checklist});

  final InspectionDraft value;
  final List<ChecklistItem> checklist;

  @override
  InspectionDraft? get draft => value;

  @override
  List<ChecklistItem> get items => checklist;
}

/// In flight. Blocking and **not** cancellable — a submission the driver can
/// abort halfway is a bus whose clearance nobody can account for.
final class InspectionSubmitting extends InspectionState {
  const InspectionSubmitting({required this.value, required this.checklist});

  final InspectionDraft value;
  final List<ChecklistItem> checklist;

  @override
  InspectionDraft? get draft => value;

  @override
  List<ChecklistItem> get items => checklist;
}

/// Accepted. Carries the **server-decided** outcome.
final class InspectionSubmitted extends InspectionState {
  const InspectionSubmitted(this.result);

  final InspectionResult result;
}

/// Offline. The draft is saved and the bus is **not** cleared.
///
/// Deliberately not called "queued for submission": an inspection cannot be
/// replayed blindly, because a safety-critical failure needs an evidence
/// upload that has not happened. Saying it will submit itself would tell a
/// driver the bus is on its way to being cleared when it is not.
final class InspectionSaved extends InspectionState {
  const InspectionSaved({required this.value, required this.checklist});

  final InspectionDraft value;
  final List<ChecklistItem> checklist;

  @override
  InspectionDraft? get draft => value;

  @override
  List<ChecklistItem> get items => checklist;
}
