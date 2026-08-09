import 'package:flutter/material.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// Which semantic ground a chip sits on.
enum StatusTone { positive, caution, critical, neutral, info }

/// Component 1 — `StatusChip`.
///
/// One word plus a mark that says what state something is in.
///
/// **Every chip carries an icon as well as colour.** A driver with deuteranopia
/// has to tell `running` from `blocked` without seeing hue, and colour-only
/// status is the most common accessibility failure in operational software.
class StatusChip extends StatelessWidget {
  const StatusChip({
    required this.label,
    required this.tone,
    required this.icon,
    this.dense = false,
    super.key,
  });

  final String label;
  final StatusTone tone;
  final AppIcon icon;

  /// Tighter, for use inside a list row.
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final theme = Theme.of(context);

    final (background, foreground) = switch (tone) {
      StatusTone.positive => (colors.positive, colors.onPositive),
      StatusTone.caution => (colors.caution, colors.onCaution),
      StatusTone.critical => (colors.critical, colors.onCritical),
      StatusTone.info => (colors.info, colors.onInfo),
      StatusTone.neutral => (colors.neutral, Colors.white),
    };

    return Semantics(
      // Reads "READY, status" rather than leaving the colour to carry it.
      label: '$label, ${_toneWord(tone)}',
      excludeSemantics: true,
      child: Container(
        padding: EdgeInsets.symmetric(
          horizontal: dense ? Spacing.sm : Spacing.md,
          vertical: dense ? Spacing.xs : Spacing.sm,
        ),
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(Radii.full),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            AppIconView(
              icon,
              size: dense ? IconSize.xs : IconSize.sm,
              color: foreground,
            ),
            const SizedBox(width: Spacing.xs),
            Text(
              label,
              style: (dense ? theme.textTheme.labelSmall : theme.textTheme.labelLarge)
                  ?.copyWith(color: foreground),
            ),
          ],
        ),
      ),
    );
  }

  String _toneWord(StatusTone tone) => switch (tone) {
        StatusTone.positive => 'ready',
        StatusTone.caution => 'attention needed',
        StatusTone.critical => 'blocked',
        StatusTone.info => 'information',
        StatusTone.neutral => 'inactive',
      };
}
