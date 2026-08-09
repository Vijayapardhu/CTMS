import 'package:flutter/material.dart';

import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// Component 16 — `EmptyState`.
///
/// **Never red, never a warning icon, never the word "error".** An empty day is
/// not a failure, and the whole reason this exists instead of reusing an error
/// view is that a driver with no trip assigned has done nothing wrong.
class EmptyState extends StatelessWidget {
  const EmptyState({
    required this.title,
    required this.icon,
    this.body,
    this.action,
    super.key,
  });

  final String title;
  final String? body;
  final AppIcon icon;

  /// Absent unless there is something the driver can genuinely do.
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(Spacing.lg),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: Sizes.maxLineLength),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              AppIconView(
                icon,
                size: IconSize.sos,
                // Deliberately the muted foreground, not a status colour.
                color: theme.colorScheme.onSurfaceVariant,
              ),
              const SizedBox(height: Spacing.lg),
              Text(
                title,
                style: theme.textTheme.headlineSmall,
                textAlign: TextAlign.center,
              ),
              if (body != null) ...[
                const SizedBox(height: Spacing.sm),
                Text(
                  body!,
                  style: theme.textTheme.bodyMedium
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  textAlign: TextAlign.center,
                ),
              ],
              if (action != null) ...[
                const SizedBox(height: Spacing.xl),
                action!,
              ],
            ],
          ),
        ),
      ),
    );
  }
}
