import 'dart:async';

import 'package:flutter/material.dart';

import '../core/services/crash_reporter.dart';
import '../core/services/logger_service.dart';
import '../core/widgets/error_boundary.dart';
import 'app.dart';
import 'config/app_config.dart';
import 'di/service_locator.dart';

/// Starts the application.
///
/// Everything that must happen before the first frame happens here, inside a
/// guarded zone, so an exception thrown during start-up is reported rather
/// than lost to a blank screen.
///
/// [builder] is injected so a test can boot the real dependency graph while
/// running a different widget on top of it.
Future<void> bootstrap({
  AppConfig? config,
  Widget Function()? builder,
}) async {
  final resolved = config ?? AppConfig.fromEnvironment();

  await runZonedGuarded(
    () async {
      WidgetsFlutterBinding.ensureInitialized();

      await configureDependencies(resolved);

      final logger = sl<LoggerService>();

      installErrorBoundary(sl<CrashReporter>());

      logger.info('Starting', context: {
        'flavor': resolved.flavor.name,
        // Not a secret, and the single most useful thing to know when a build
        // turns out to be talking to the wrong environment.
        'api': resolved.apiBaseUrl,
      });

      runApp(builder?.call() ?? const CtmsDriverApp());
    },
    _onUncaught,
  );
}

/// Last line of defence. Registered inside the zone so it also catches errors
/// raised while the dependency graph is still being built — which is exactly
/// when nothing else is listening.
void _onUncaught(Object error, StackTrace stack) {
  if (sl.isRegistered<CrashReporter>()) {
    sl<CrashReporter>().recordError(error, stack, fatal: true);
  }
  if (sl.isRegistered<LoggerService>()) {
    sl<LoggerService>().error('Uncaught', error: error, stackTrace: stack);
  }
}
