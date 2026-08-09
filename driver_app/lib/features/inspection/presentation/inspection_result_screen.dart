import 'package:flutter/material.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/checklist.dart';

/// P11 — the result.
///
/// Renders `data.outcome` from the 201 and nothing else. The outcome is
/// **server-decided**: a driver told "this will fail" who then sees
/// `PASSED_WITH_DEFECTS` stops trusting the app, so it is never predicted here
/// or anywhere else.
class InspectionResultScreen extends StatelessWidget {
  const InspectionResultScreen({
    required this.result,
    required this.onDone,
    super.key,
  });

  final InspectionResult result;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    final (title, tone, icon) = switch (result.outcome) {
      InspectionOutcome.passed => (
          strings.inspectionResultPassed,
          colors.positive,
          AppIcon.success,
        ),
      InspectionOutcome.passedWithDefects => (
          strings.inspectionResultDefects,
          colors.caution,
          AppIcon.warning,
        ),
      InspectionOutcome.failed => (
          strings.inspectionResultFailed,
          colors.critical,
          AppIcon.blocked,
        ),
      // A value this build does not know. Saying nothing useful is safer than
      // guessing that it cleared.
      InspectionOutcome.unknown => (
          strings.inspectionResultDefects,
          colors.neutral,
          AppIcon.info,
        ),
    };

    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(Spacing.lg),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AppIconView(icon, size: IconSize.sos, color: tone),
              const SizedBox(height: Spacing.lg),
              Text(
                title,
                style: theme.textTheme.headlineMedium?.copyWith(color: tone),
                textAlign: TextAlign.center,
              ),
              if (result.openedTicket) ...[
                const SizedBox(height: Spacing.md),
                Text(
                  strings.inspectionTicketOpened,
                  style: theme.textTheme.bodyMedium,
                  textAlign: TextAlign.center,
                ),
              ],
              const SizedBox(height: Spacing.xl),
              SizedBox(
                height: Sizes.buttonProminent,
                child: FilledButton(
                  onPressed: onDone,
                  child: Text(strings.inspectionDone),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
