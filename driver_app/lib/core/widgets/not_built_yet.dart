import 'package:flutter/material.dart';

import '../../l10n/app_localizations.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// The body of a tab whose feature slice has not been built.
///
/// Explicit rather than an empty `Container`: a blank screen is
/// indistinguishable from a screen that failed to load, and this one says
/// which it is.
class NotBuiltYet extends StatelessWidget {
  const NotBuiltYet({required this.icon, super.key});

  final AppIcon icon;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Spacing.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AppIconView(
              icon,
              size: IconSize.xl,
              color: theme.colorScheme.onSurfaceVariant,
            ),
            const SizedBox(height: Spacing.md),
            Text(strings.comingSoon, style: theme.textTheme.titleLarge),
            const SizedBox(height: Spacing.sm),
            Text(
              strings.comingSoonBody,
              style: theme.textTheme.bodyMedium,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}
