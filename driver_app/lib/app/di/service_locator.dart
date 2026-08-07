import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../core/api/api_client.dart';
import '../../core/connectivity/connectivity_service.dart';
import '../../core/services/analytics_service.dart';
import '../../core/services/crash_reporter.dart';
import '../../core/services/logger_service.dart';
import '../../core/services/ui_service.dart';
import '../../core/storage/secure_store.dart';
import '../config/app_config.dart';
import '../lifecycle/app_lifecycle_observer.dart';
import '../settings/app_preferences.dart';

/// The service locator.
///
/// Read only from composition points — `bootstrap`, `app.dart`, the router,
/// and a bloc's constructor. A widget that reaches into it mid-build is a
/// widget that cannot be tested, so screens receive what they need instead.
final GetIt sl = GetIt.instance;

/// Registers everything the application needs.
///
/// Singletons only where the specification says the thing is application-wide.
/// Feature blocs are constructed per route in later slices, so a screen cannot
/// outlive its own state.
Future<void> configureDependencies(AppConfig config) async {
  final prefs = await SharedPreferences.getInstance();

  sl
    ..registerSingleton<AppConfig>(config)
    ..registerSingleton<SharedPreferences>(prefs)
    ..registerSingleton<GlobalKey<NavigatorState>>(GlobalKey<NavigatorState>())
    ..registerSingleton<GlobalKey<ScaffoldMessengerState>>(
        GlobalKey<ScaffoldMessengerState>())
    ..registerSingleton<AppPreferences>(AppPreferences(prefs, config))
    ..registerSingleton<LoggerService>(
        ConsoleLoggerService(verbose: config.enableVerboseLogging))
    ..registerSingleton<CrashReporter>(const NoopCrashReporter())
    ..registerSingleton<AnalyticsService>(const NoopAnalyticsService())
    ..registerSingleton<AppLifecycleObserver>(
        AppLifecycleObserver(sl<LoggerService>()))
    ..registerSingleton<SecureStore>(const FlutterSecureStore(
        FlutterSecureStorage(aOptions: FlutterSecureStore.androidOptions)))
    ..registerSingleton<ConnectivityService>(
        DefaultConnectivityService(Connectivity()))
    ..registerSingleton<UiService>(UiService(
      sl<GlobalKey<NavigatorState>>(),
      sl<GlobalKey<ScaffoldMessengerState>>(),
    ))
    ..registerSingleton<ApiClient>(ApiClient(
      baseUrl: config.apiBaseUrl,
      logger: sl<LoggerService>(),
      // Slice 1 replaces this with the live session's token. Until then the
      // client is wired but unauthenticated, which is correct: nothing calls
      // it, and no token has been issued.
      tokenSupplier: () => sl<SecureStore>().read(SecureKeys.accessToken),
    ));
}

/// Clears every registration. Used between tests.
Future<void> resetDependencies() => sl.reset();
