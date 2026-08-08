import 'package:flutter/material.dart';

import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';

/// P0 — shown while stored tokens are read and checked.
///
/// Brief in the good case and up to a network timeout in the bad one, which is
/// why it carries a spinner rather than a static logo: a driver watching a
/// still screen for eight seconds concludes the app has hung.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AppIconView(
              AppIcon.trip,
              size: IconSize.sos,
              color: theme.colorScheme.primary,
            ),
            const SizedBox(height: Spacing.lg),
            const SizedBox.square(
              dimension: IconSize.md,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          ],
        ),
      ),
    );
  }
}
