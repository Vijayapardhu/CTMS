import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/consequence_panel.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/checklist.dart';
import '../domain/inspection_state.dart';
import 'bloc/inspection_bloc.dart';
import 'widgets/quick_check.dart';

/// P10 — review and submit.
///
/// The consequence sits above the fold whenever a safety-critical item failed.
/// A driver who has just failed a brake check needs to know the bus is about to
/// be grounded while they can still ring the depot.
class InspectionReviewScreen extends StatelessWidget {
  const InspectionReviewScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return BlocBuilder<InspectionBloc, InspectionState>(
      builder: (context, state) {
        final draft = state.draft;
        final checklist = state.items;
        if (draft == null) return const SizedBox.shrink();

        final submitting = state is InspectionSubmitting;
        final grounds = draft.groundsTheBus(checklist);
        final failures = checklist
            .where((i) => draft.answers[i.code]?.verdict == Verdict.failed)
            .toList(growable: false);

        return PopScope(
          // A submission in flight is not cancellable: a bus whose clearance
          // nobody can account for is worse than a driver waiting.
          canPop: !submitting,
          child: Scaffold(
            appBar: AppBar(
              title: Text(failures.isEmpty
                  ? strings.quickReady
                  : strings.inspectionReviewTitle),
            ),
            body: AbsorbPointer(
              absorbing: submitting,
              child: ListView(
                padding: const EdgeInsets.all(Spacing.md),
                children: [
                  if (state is InspectionSaved) ...[
                    ConsequencePanel(
                      severity: ConsequenceSeverity.warning,
                      title: strings.inspectionSavedTitle,
                      body: strings.inspectionSavedBody,
                    ),
                    const SizedBox(height: Spacing.lg),
                  ],
                  if (grounds) ...[
                    ConsequencePanel(
                      severity: ConsequenceSeverity.danger,
                      title: strings.inspectionGroundedTitle,
                      body: strings.inspectionGroundedBody,
                    ),
                    const SizedBox(height: Spacing.lg),
                  ],
                  // Counted from the server's own list, never from 14.
                  InspectionSummary(
                    passed: draft.passedCount(checklist),
                    total: checklist.length,
                    issues: failures.length,
                  ),
                  const SizedBox(height: Spacing.md),
                  Text(
                    strings.inspectionOdometerReading('${draft.odometer}'),
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  // Only the exceptions get a row. A clean inspection has
                  // nothing here to read, which is the point.
                  if (failures.isNotEmpty) ...[
                    const SizedBox(height: Spacing.lg),
                    for (final item in failures) _Failure(item, draft),
                  ],
                ],
              ),
            ),
            bottomNavigationBar: SafeArea(
              minimum: const EdgeInsets.all(Spacing.md),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  SizedBox(
                    height: Sizes.buttonProminent,
                    child: FilledButton(
                      onPressed: submitting
                          ? null
                          : () => context
                              .read<InspectionBloc>()
                              .add(const SubmissionRequested()),
                      child: submitting
                          ? const SizedBox.square(
                              dimension: IconSize.sm,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text(failures.isEmpty
                              ? strings.quickConfirmSubmit
                              : strings.inspectionSubmit),
                    ),
                  ),
                  if (!submitting)
                    TextButton(
                      onPressed: () => context
                          .read<InspectionBloc>()
                          .add(const EditingResumed()),
                      child: Text(failures.isEmpty
                          ? strings.quickGoBack
                          : strings.inspectionBack),
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _Failure extends StatelessWidget {
  const _Failure(this.item, this.draft);

  final ChecklistItem item;
  final InspectionDraft draft;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AppIconView(
            item.safetyCritical ? AppIcon.safetyCritical : AppIcon.warning,
            size: IconSize.sm,
            color: item.safetyCritical ? colors.critical : colors.caution,
          ),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.label, style: theme.textTheme.titleSmall),
                if (draft.answers[item.code]?.notes != null)
                  Text(
                    draft.answers[item.code]!.notes!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
