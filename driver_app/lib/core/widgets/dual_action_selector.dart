import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';

/// Component 3 — `DualActionSelector`.
///
/// A binary choice where both options are real and **neither is a default**.
///
/// `value` starts null on purpose. On an inspection, a pre-selected "Pass"
/// would let a driver submit fourteen passes without ever looking at the bus,
/// and a pre-selected "Fail" would ground buses that are fine.
class DualActionSelector<T> extends StatelessWidget {
  const DualActionSelector({
    required this.leftLabel,
    required this.rightLabel,
    required this.leftValue,
    required this.rightValue,
    required this.value,
    required this.onChanged,
    this.danger = false,
    this.enabled = true,
    super.key,
  });

  final String leftLabel;
  final String rightLabel;
  final T leftValue;
  final T rightValue;

  /// Null until the driver chooses.
  final T? value;

  final ValueChanged<T>? onChanged;

  /// Colours the right-hand option as a consequence rather than a preference.
  final bool danger;

  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _Option(
            label: leftLabel,
            selected: value == leftValue,
            enabled: enabled,
            tone: context.ctms.positive,
            onTap: () => _choose(leftValue),
          ),
        ),
        const SizedBox(width: Spacing.sm),
        Expanded(
          child: _Option(
            label: rightLabel,
            selected: value == rightValue,
            enabled: enabled,
            tone: danger ? context.ctms.critical : context.ctms.caution,
            onTap: () => _choose(rightValue),
          ),
        ),
      ],
    );
  }

  void _choose(T next) {
    if (!enabled || onChanged == null) return;

    // A driver working down fourteen items with gloves on relies on the tick
    // to know the tap registered, without looking back up.
    HapticFeedback.selectionClick();
    onChanged!(next);
  }
}

class _Option extends StatelessWidget {
  const _Option({
    required this.label,
    required this.selected,
    required this.enabled,
    required this.tone,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final bool enabled;
  final Color tone;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final foreground = selected ? Colors.white : theme.colorScheme.onSurface;

    return Semantics(
      button: true,
      selected: selected,
      enabled: enabled,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: selected ? tone : theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(Radii.sm),
        child: InkWell(
          onTap: enabled ? onTap : null,
          borderRadius: BorderRadius.circular(Radii.sm),
          child: Container(
            // Each option is at least 56dp tall and they share the width
            // equally: neither reads as the one the app expects.
            height: Sizes.buttonHeight,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(Radii.sm),
              border: Border.all(
                color: selected ? tone : theme.colorScheme.outlineVariant,
                width: selected ? 2 : 1,
              ),
            ),
            child: Text(
              label,
              style: theme.textTheme.titleMedium?.copyWith(
                color: enabled ? foreground : theme.disabledColor,
                fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
