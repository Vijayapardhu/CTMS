import 'package:flutter/material.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

enum ConsequenceSeverity { info, warning, danger }

/// Component 11 — `ConsequencePanel`.
///
/// States what is about to happen, **before** it happens.
///
/// Never used after the fact — that is a snackbar's job. This exists because
/// several flows do something irreversible: grounding a bus, stranding waiting
/// students, withdrawing an emergency alert. A driver who fails a brake check
/// needs to know the bus is about to be taken off the road while they can
/// still ring the depot, not once it has happened.
class ConsequencePanel extends StatelessWidget {
  const ConsequencePanel({
    required this.severity,
    required this.title,
    required this.body,
    super.key,
  });

  final ConsequenceSeverity severity;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final theme = Theme.of(context);

    final (tone, icon) = switch (severity) {
      ConsequenceSeverity.info => (colors.info, AppIcon.info),
      ConsequenceSeverity.warning => (colors.caution, AppIcon.warning),
      ConsequenceSeverity.danger => (colors.critical, AppIcon.error),
    };

    return Semantics(
      container: true,
      label: '$title. $body',
      excludeSemantics: true,
      child: Container(
        padding: const EdgeInsets.all(Spacing.md),
        decoration: BoxDecoration(
          color: tone.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(Radii.md),
          border: Border.all(color: tone, width: 2),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                // Colour never carries this alone.
                AppIconView(icon, size: IconSize.sm, color: tone),
                const SizedBox(width: Spacing.sm),
                Expanded(
                  child: Text(
                    title,
                    style: theme.textTheme.titleMedium?.copyWith(color: tone),
                  ),
                ),
              ],
            ),
            const SizedBox(height: Spacing.sm),
            Text(body, style: theme.textTheme.bodyMedium),
          ],
        ),
      ),
    );
  }
}
