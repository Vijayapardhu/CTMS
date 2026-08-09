import 'package:flutter/material.dart';

import '../../../../core/design_system/ctms_colors.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../core/widgets/dual_action_selector.dart';
import '../../../../l10n/app_localizations.dart';
import '../../domain/checklist.dart';

/// Component 9 — `ChecklistItemTile`.
///
/// Four states: unanswered · passed · failed · failed-incomplete.
///
/// `failed-incomplete` is a real state and it blocks review. It is what stops a
/// driver failing the brakes and walking away without a note or a photograph.
class ChecklistItemTile extends StatelessWidget {
  const ChecklistItemTile({
    required this.item,
    required this.answer,
    required this.problem,
    required this.onVerdict,
    required this.onNotes,
    this.highlighted = false,
    super.key,
  });

  final ChecklistItem item;
  final ItemAnswer? answer;

  /// Why this item is not yet complete, from the draft.
  final AnswerProblem? problem;

  final ValueChanged<Verdict> onVerdict;
  final ValueChanged<String> onNotes;

  /// The server named this item in a rejection.
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.ctms;
    final strings = AppStrings.of(context);
    final failed = answer?.verdict == Verdict.failed;

    return Card(
      margin: const EdgeInsets.only(bottom: Spacing.md),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(Radii.md),
        side: highlighted
            ? BorderSide(color: colors.critical, width: 2)
            : BorderSide.none,
      ),
      child: Padding(
        padding: const EdgeInsets.all(Spacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(item.label, style: theme.textTheme.titleMedium),
                ),
                // Safety-critical items keep their marker even when passing —
                // the driver should know which ones ground the bus before they
                // decide, not after.
                if (item.safetyCritical) ...[
                  const SizedBox(width: Spacing.sm),
                  Semantics(
                    label: strings.inspectionSafetyCritical,
                    excludeSemantics: true,
                    child: AppIconView(
                      AppIcon.safetyCritical,
                      size: IconSize.sm,
                      color: colors.critical,
                    ),
                  ),
                ],
              ],
            ),
            const SizedBox(height: Spacing.md),
            DualActionSelector<Verdict>(
              leftLabel: strings.inspectionPass,
              rightLabel: strings.inspectionFail,
              leftValue: Verdict.passed,
              rightValue: Verdict.failed,
              value: answer?.verdict,
              danger: item.safetyCritical,
              onChanged: onVerdict,
            ),

            // Expands on fail to reveal the note and, for a critical item, the
            // photograph requirement.
            if (failed) ...[
              const SizedBox(height: Spacing.md),
              TextFormField(
                initialValue: answer?.notes,
                onChanged: onNotes,
                maxLines: 3,
                maxLength: InspectionDraft.maxNoteLength,
                decoration: InputDecoration(
                  labelText: strings.inspectionNotesLabel,
                  errorText: switch (problem) {
                    AnswerProblem.notesMissing ||
                    AnswerProblem.notesTooShort =>
                      strings.inspectionNotesRequired,
                    _ => null,
                  },
                ),
              ),
              if (item.safetyCritical)
                _EvidenceRequirement(
                  satisfied: (answer?.evidenceId ?? '').isNotEmpty,
                  label: item.label,
                ),
            ],
          ],
        ),
      ),
    );
  }
}

/// States the photograph requirement for a failed safety-critical item.
///
/// Slice 4 records the requirement and blocks review on it; capture itself is
/// the evidence slice. Showing a camera button that does nothing would be a
/// worse lie than saying plainly what is still needed.
class _EvidenceRequirement extends StatelessWidget {
  const _EvidenceRequirement({required this.satisfied, required this.label});

  final bool satisfied;
  final String label;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final tone = satisfied ? colors.positive : colors.critical;

    return Padding(
      padding: const EdgeInsets.only(top: Spacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AppIconView(
            satisfied ? AppIcon.success : AppIcon.camera,
            size: IconSize.sm,
            color: tone,
          ),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Text(
              strings.inspectionEvidenceRequired(label),
              style: theme.textTheme.bodySmall?.copyWith(color: tone),
            ),
          ),
        ],
      ),
    );
  }
}
