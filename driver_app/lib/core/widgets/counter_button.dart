import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// Component 4 — `CounterButton`.
///
/// Sized for a gloved thumb against a moving bus, and given haptic feedback,
/// because a driver counting students through a door is not looking at the
/// screen while they tap. The confirmation they get is in their hand.
///
/// Deliberately has no long-press or repeat: every tap is one person, and an
/// accelerating counter is how a bus ends up carrying forty-one.
class CounterButton extends StatelessWidget {
  const CounterButton({
    required this.icon,
    required this.label,
    required this.onPressed,
    this.tone,
    this.size = Sizes.counterButton,
    super.key,
  });

  final AppIcon icon;
  final String label;

  /// Null disables the button — the bus is full, or the trip is not running.
  final VoidCallback? onPressed;

  final Color? tone;
  final double size;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final enabled = onPressed != null;
    final colour = tone ?? theme.colorScheme.primary;

    return Semantics(
      button: true,
      enabled: enabled,
      label: label,
      excludeSemantics: true,
      child: SizedBox(
        width: size,
        height: size,
        child: Material(
          color: enabled
              ? colour.withValues(alpha: 0.14)
              : theme.colorScheme.surfaceContainerHighest,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Radii.md),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(Radii.md),
            onTap: enabled
                ? () {
                    HapticFeedback.mediumImpact();
                    onPressed!();
                  }
                : null,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                AppIconView(
                  icon,
                  size: IconSize.lg,
                  color: enabled ? colour : theme.disabledColor,
                ),
                const SizedBox(height: Spacing.xs),
                Text(
                  label,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: enabled ? colour : theme.disabledColor,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
