/// One line of the pre-trip checklist, as the server defines it.
///
/// **Never hard-coded.** `GET /inspections/checklist` is the only source; a
/// list baked into the app drifts the moment operations add an item, and the
/// submission would then be rejected as incomplete with no way for the driver
/// to see why.
class ChecklistItem {
  const ChecklistItem({
    required this.code,
    required this.label,
    required this.safetyCritical,
  });

  /// The enum value the API expects back, e.g. `BRAKES`. UPPERCASE, compared
  /// as-is — never lower-cased on the way through.
  final String code;

  /// What the driver reads.
  final String label;

  /// A failure here grounds the bus and requires a photograph.
  final bool safetyCritical;

  static ChecklistItem fromJson(Map<String, dynamic> json) {
    return ChecklistItem(
      code: json['item'] as String? ?? '',
      label: json['label'] as String? ?? '',
      safetyCritical: json['safety_critical'] == true,
    );
  }
}

/// What the driver said about one item.
///
/// Deliberately nullable at the call site: there is no default. A checklist
/// that starts with everything passing is a checklist nobody read.
enum Verdict { passed, failed }

/// The driver's answer for a single item, with whatever it needs to be
/// complete.
class ItemAnswer {
  const ItemAnswer({required this.verdict, this.notes, this.evidenceId});

  final Verdict verdict;

  /// Required on a failure, 5–500 characters. The server takes it verbatim
  /// into the maintenance ticket, so it is the workshop's only description.
  final String? notes;

  /// Required when a safety-critical item fails. Slice 4 records the
  /// requirement; capture arrives with the evidence slice.
  final String? evidenceId;

  ItemAnswer copyWith({Verdict? verdict, String? notes, String? evidenceId}) {
    return ItemAnswer(
      verdict: verdict ?? this.verdict,
      notes: notes ?? this.notes,
      evidenceId: evidenceId ?? this.evidenceId,
    );
  }

  Map<String, dynamic> toJson() => {
        'verdict': verdict.name,
        if (notes != null) 'notes': notes,
        if (evidenceId != null) 'evidence_id': evidenceId,
      };

  static ItemAnswer? fromJson(Object? value) {
    if (value is! Map<String, dynamic>) return null;

    final verdict = switch (value['verdict']) {
      'passed' => Verdict.passed,
      'failed' => Verdict.failed,
      _ => null,
    };
    if (verdict == null) return null;

    return ItemAnswer(
      verdict: verdict,
      notes: value['notes'] as String?,
      evidenceId: value['evidence_id'] as String?,
    );
  }
}

/// Why an answer is not yet good enough to submit.
///
/// `failedIncomplete` is a real state, not a validation afterthought. It is
/// what stops a driver failing the brakes and walking away.
enum AnswerProblem { unanswered, notesMissing, notesTooShort, evidenceMissing }

/// The whole in-progress inspection.
///
/// Persisted on every change. A driver who fills in fourteen items in the cold
/// and loses them to a battery death does not fill them in again honestly.
class InspectionDraft {
  const InspectionDraft({
    required this.busId,
    this.odometer,
    this.answers = const {},
    this.notes,
  });

  final String busId;

  /// Kilometres. Must be at least the bus's recorded total (BR-061); the
  /// server answers 409, not 422, when it is lower.
  final int? odometer;

  /// Keyed by [ChecklistItem.code].
  final Map<String, ItemAnswer> answers;

  final String? notes;

  InspectionDraft copyWith({
    int? odometer,
    Map<String, ItemAnswer>? answers,
    String? notes,
  }) {
    return InspectionDraft(
      busId: busId,
      odometer: odometer ?? this.odometer,
      answers: answers ?? this.answers,
      notes: notes ?? this.notes,
    );
  }

  /// Why [item] cannot be submitted yet, or null when it is fine.
  AnswerProblem? problemWith(ChecklistItem item) {
    final answer = answers[item.code];

    if (answer == null) return AnswerProblem.unanswered;
    if (answer.verdict == Verdict.passed) return null;

    final notes = answer.notes?.trim() ?? '';
    if (notes.isEmpty) return AnswerProblem.notesMissing;
    if (notes.length < minNoteLength) return AnswerProblem.notesTooShort;

    if (item.safetyCritical && (answer.evidenceId ?? '').isEmpty) {
      return AnswerProblem.evidenceMissing;
    }

    return null;
  }

  int answeredCount(List<ChecklistItem> items) =>
      items.where((i) => answers.containsKey(i.code)).length;

  List<ChecklistItem> unresolved(List<ChecklistItem> items) =>
      items.where((i) => problemWith(i) != null).toList(growable: false);

  bool get hasAnyAnswer => answers.isNotEmpty || odometer != null;

  /// Any safety-critical failure grounds the bus. Used to decide whether the
  /// consequence panel appears — never to predict the outcome, which is the
  /// server's to decide.
  bool groundsTheBus(List<ChecklistItem> items) => items.any(
        (i) => i.safetyCritical && answers[i.code]?.verdict == Verdict.failed,
      );

  /// The submission body, in the shape `POST /buses/{id}/inspections` expects.
  ///
  /// **Every** item is present. A partial submission is a 422, so the caller
  /// passes the full checklist rather than only what was touched.
  Map<String, dynamic> toSubmission(List<ChecklistItem> items) {
    return {
      'odometer_reading': odometer,
      'notes': notes,
      'items': [
        for (final item in items)
          {
            'item': item.code,
            'passed': answers[item.code]?.verdict == Verdict.passed,
            if (answers[item.code]?.notes != null)
              'notes': answers[item.code]!.notes,
            if (answers[item.code]?.evidenceId != null)
              'evidence_id': answers[item.code]!.evidenceId,
          },
      ],
    };
  }

  Map<String, dynamic> toJson() => {
        'bus_id': busId,
        'odometer': odometer,
        'notes': notes,
        'answers': answers.map((k, v) => MapEntry(k, v.toJson())),
      };

  static InspectionDraft? fromJson(Map<String, dynamic> json) {
    final busId = json['bus_id'] as String?;
    if (busId == null || busId.isEmpty) return null;

    final raw = json['answers'];
    final answers = <String, ItemAnswer>{};

    if (raw is Map) {
      raw.forEach((key, value) {
        final answer = ItemAnswer.fromJson(value);
        if (answer != null) answers['$key'] = answer;
      });
    }

    return InspectionDraft(
      busId: busId,
      odometer: json['odometer'] as int?,
      notes: json['notes'] as String?,
      answers: answers,
    );
  }

  static const minNoteLength = 5;
  static const maxNoteLength = 500;
}

/// What the server decided. Never computed in the app.
enum InspectionOutcome {
  passed,
  passedWithDefects,
  failed,
  unknown;

  static InspectionOutcome fromJson(Object? value) => switch (value) {
        'PASSED' => InspectionOutcome.passed,
        'PASSED_WITH_DEFECTS' => InspectionOutcome.passedWithDefects,
        'FAILED' => InspectionOutcome.failed,
        _ => InspectionOutcome.unknown,
      };

  /// Whether the bus may carry anyone after this result.
  bool get clearsTheBus =>
      this == InspectionOutcome.passed || this == InspectionOutcome.passedWithDefects;
}

/// The accepted submission, as the 201 returns it.
class InspectionResult {
  const InspectionResult({
    required this.id,
    required this.outcome,
    this.maintenanceTicketId,
  });

  final String id;
  final InspectionOutcome outcome;

  /// Present when the outcome opened a workshop ticket.
  final String? maintenanceTicketId;

  bool get openedTicket => maintenanceTicketId != null;

  static InspectionResult fromJson(Map<String, dynamic> json) {
    return InspectionResult(
      id: json['id'] as String? ?? '',
      outcome: InspectionOutcome.fromJson(json['outcome']),
      maintenanceTicketId: json['maintenance_ticket_id'] as String?,
    );
  }
}
