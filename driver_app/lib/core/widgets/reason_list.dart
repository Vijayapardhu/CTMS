import 'package:flutter/material.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// Component 6 — `ReasonList`.
///
/// Renders `reasons[]` from the API **grouped by whether the driver can act**.
///
/// This component exists because the backend deliberately returns every
/// blocking reason at once rather than the first. Rendering only one, or
/// rendering them undifferentiated, both defeat that: a driver completes the
/// inspection, comes back, and is still blocked by an expired insurance
/// certificate with no idea why.
class ReasonList extends StatelessWidget {
  const ReasonList({
    required this.actionable,
    required this.blocking,
    required this.actionableHeading,
    required this.blockingHeading,
    super.key,
  });

  /// What the driver can fix themselves.
  final List<String> actionable;

  /// What only operations can fix.
  final List<String> blocking;

  final String actionableHeading;
  final String blockingHeading;

  @override
  Widget build(BuildContext context) {
    // Empty renders nothing at all — it is not an empty state.
    if (actionable.isEmpty && blocking.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Actionable first, always. It is the group the driver can leave with.
        if (actionable.isNotEmpty)
          _Group(
            heading: actionableHeading,
            reasons: actionable,
            emphasised: true,
          ),
        if (actionable.isNotEmpty && blocking.isNotEmpty)
          const SizedBox(height: Spacing.lg),
        if (blocking.isNotEmpty)
          _Group(
            heading: blockingHeading,
            reasons: blocking,
            emphasised: false,
          ),
      ],
    );
  }
}

class _Group extends StatelessWidget {
  const _Group({
    required this.heading,
    required this.reasons,
    required this.emphasised,
  });

  final String heading;
  final List<String> reasons;

  /// The quieter treatment is what makes the split legible at a glance.
  final bool emphasised;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.ctms;
    final accent = emphasised ? colors.caution : theme.colorScheme.onSurfaceVariant;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          heading,
          style: theme.textTheme.labelLarge?.copyWith(color: accent),
        ),
        const SizedBox(height: Spacing.sm),
        for (final reason in reasons)
          Padding(
            padding: const EdgeInsets.only(bottom: Spacing.sm),
            child: Container(
              padding: const EdgeInsets.all(Spacing.md),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(Radii.sm),
                border: emphasised
                    ? Border(left: BorderSide(color: accent, width: 4))
                    : null,
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  AppIconView(
                    emphasised ? AppIcon.warning : AppIcon.blocked,
                    size: IconSize.sm,
                    color: accent,
                  ),
                  const SizedBox(width: Spacing.sm),
                  // The server's words, verbatim. They are written for drivers.
                  Expanded(
                    child: Text(reason, style: theme.textTheme.bodyMedium),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
