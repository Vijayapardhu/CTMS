import 'package:flutter/material.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import 'constrained_text_scale.dart';

/// Component 5 — `BigNumberDisplay`.
///
/// The occupancy figure, sized to be read at a glance from a driving position
/// rather than studied. It is the one number on the screen that changes as the
/// driver works, so it gets the space.
///
/// [pending] marks taps the server has not acknowledged. Subtle on purpose: the
/// count is not wrong, it is simply not yet confirmed, and a loud warning would
/// tell a driver to stop counting when they should carry on.
class BigNumberDisplay extends StatelessWidget {
  const BigNumberDisplay({
    required this.value,
    required this.label,
    this.total,
    this.pending = 0,
    this.pendingLabel,
    this.tone,
    super.key,
  });

  final int value;

  /// Rendered as `value / total` when present.
  final int? total;

  final String label;
  final int pending;
  final String? pendingLabel;
  final Color? tone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colour = tone ?? theme.colorScheme.onSurface;

    return ConstrainedTextScale(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: theme.textTheme.labelLarge
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                '$value',
                style: theme.textTheme.displaySmall
                    ?.copyWith(fontWeight: FontWeight.bold, color: colour),
              ),
              if (total != null) ...[
                const SizedBox(width: Spacing.xs),
                Text(
                  '/ $total',
                  style: theme.textTheme.titleLarge
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ],
          ),
          if (pending > 0 && pendingLabel != null)
            Text(
              pendingLabel!,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: context.ctms.caution),
            ),
        ],
      ),
    );
  }
}
