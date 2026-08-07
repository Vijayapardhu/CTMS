import 'package:flutter/material.dart';

import '../../l10n/app_localizations.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';
import '../services/crash_reporter.dart';

/// Replaces Flutter's red error screen.
///
/// A driver seeing a red-and-yellow stack trace mid-route has no idea whether
/// the trip is still recording. This shows a plain message, reports the error,
/// and states what the failure did *not* break.
class AppErrorView extends StatelessWidget {
  const AppErrorView({this.onRetry, super.key});

  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(Spacing.lg),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                AppIconView(
                  AppIcon.error,
                  size: IconSize.xl,
                  color: Theme.of(context).colorScheme.error,
                ),
                const SizedBox(height: Spacing.md),
                Text(
                  strings.errorTitle,
                  style: Theme.of(context).textTheme.titleLarge,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: Spacing.sm),
                Text(
                  strings.errorBody,
                  style: Theme.of(context).textTheme.bodyMedium,
                  textAlign: TextAlign.center,
                ),
                if (onRetry != null) ...[
                  const SizedBox(height: Spacing.xl),
                  FilledButton(
                    onPressed: onRetry,
                    child: Text(strings.errorRetry),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Installs [AppErrorView] as the global build-error widget.
///
/// Release only. In debug the red screen is more useful than a polite one.
void installErrorBoundary(CrashReporter reporter) {
  final previous = FlutterError.onError;

  FlutterError.onError = (details) {
    reporter.recordError(details.exception, details.stack);
    previous?.call(details);
  };

  ErrorWidget.builder = (details) {
    if (_isDebug) return ErrorWidget(details.exception);
    return const AppErrorView();
  };
}

const bool _isDebug = !bool.fromEnvironment('dart.vm.product');
