import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../core/api/api_client.dart';
import '../../core/connectivity/connectivity_cubit.dart';
import '../../core/connectivity/connectivity_service.dart';
import '../../core/services/analytics_service.dart';
import '../../core/services/crash_reporter.dart';
import '../../core/services/logger_service.dart';
import '../../core/services/permission_service.dart';
import '../../core/services/ui_service.dart';
import '../../core/storage/secure_store.dart';
import '../../features/auth/data/auth_api.dart';
import '../../features/auth/data/session_manager.dart';
import '../../features/auth/data/session_store.dart';
import '../../features/auth/presentation/bloc/session_bloc.dart';
import '../../features/evidence/data/photo_capture.dart';
import '../../features/trip/data/trip_api.dart';
import '../../features/trip/data/trip_repository.dart';
import '../../features/trip/presentation/bloc/trip_bloc.dart';
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
/// Singletons only where the thing is genuinely application-wide. Feature
/// blocs are constructed per route in later slices; [SessionBloc] is the
/// exception, because the session outlives every screen and the router itself
/// depends on it.
/// Names the unauthenticated client, so a test can reach it to install an
/// adapter and a diagnostics screen can report which host it talks to.
const authClientName = 'auth';

/// [secureStore] and [connectivity] are the only two pieces backed by a
/// platform channel. They are parameters rather than hard-wired so a test can
/// substitute them at the composition root — which is what a composition root
/// is for — without any other file knowing.
///
/// [retryDelays] is the third. The client's back-off is real elapsed time, and
/// a widget test that has to sit through 1s + 3s for every unreachable
/// endpoint stops being a test and becomes a wait. The default is production's
/// own; the retry policy itself is covered where it belongs, against an
/// [ApiClient] constructed directly.
Future<void> configureDependencies(
  AppConfig config, {
  SecureStore? secureStore,
  ConnectivityService? connectivity,
  List<Duration>? retryDelays,
  PhotoCapture? photoCapture,
  PermissionService? permissions,
}) async {
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
    ..registerSingleton<SecureStore>(secureStore ??
        const FlutterSecureStore(
            FlutterSecureStorage(aOptions: FlutterSecureStore.androidOptions)))
    ..registerSingleton<ConnectivityService>(
        connectivity ?? DefaultConnectivityService(Connectivity()))
    // The camera and the permission dialogs are platform channels, so they
    // join the other two substitutions a test makes at the composition root.
    ..registerSingleton<PhotoCapture>(photoCapture ?? DevicePhotoCapture())
    ..registerSingleton<PermissionService>(
        permissions ?? const DevicePermissionService())
    ..registerSingleton<UiService>(UiService(
      sl<GlobalKey<NavigatorState>>(),
      sl<GlobalKey<ScaffoldMessengerState>>(),
    ));

  // The authentication layer gets its own client with **no** session attached,
  // so `POST /auth/refresh` can never trigger a refresh of its own and a failed
  // login can never be mistaken for an expiry.
  //
  // It still reports reachability. A driver who cannot sign in because the API
  // is unreachable needs the banner as much as one mid-trip does — more, since
  // sign-in is the one action that cannot be queued.
  final authClient = ApiClient(
    baseUrl: config.apiBaseUrl,
    logger: sl<LoggerService>(),
    connectivity: sl<ConnectivityService>(),
    retryDelays: retryDelays,
  );

  final manager = SessionManager(
    api: AuthApi(authClient),
    store: SessionStore(sl<SecureStore>(), sl<LoggerService>()),
    logger: sl<LoggerService>(),
  );

  sl
    ..registerSingleton<ApiClient>(authClient, instanceName: authClientName)
    ..registerSingleton<SessionManager>(manager)
    // The client every feature uses. It attaches the bearer and recovers once
    // from a 401 by asking the manager to refresh.
    ..registerSingleton<ApiClient>(ApiClient(
      baseUrl: config.apiBaseUrl,
      logger: sl<LoggerService>(),
      session: manager,
      connectivity: sl<ConnectivityService>(),
      retryDelays: retryDelays,
    ))
    ..registerSingleton<SessionBloc>(SessionBloc(
      manager: manager,
      crashReporter: sl<CrashReporter>(),
      analytics: sl<AnalyticsService>(),
    ))
    // M7. App-scoped like the session, because every machine that follows
    // subscribes to it and a driver switching tabs must not restart it.
    ..registerSingleton<ConnectivityCubit>(
        ConnectivityCubit(sl<ConnectivityService>()));

  // M1. App-scoped too: a driver checking the map mid-trip must come back to
  // the same trip rather than to a fresh load.
  sl.registerSingleton<TripBloc>(TripBloc(
    repository: TripRepository(TripApi(sl<ApiClient>())),
    logger: sl<LoggerService>(),
  ));
}

/// Clears every registration. Used between tests and on a full restart.
Future<void> resetDependencies() async {
  if (sl.isRegistered<TripBloc>()) await sl<TripBloc>().close();
  if (sl.isRegistered<ConnectivityCubit>()) {
    await sl<ConnectivityCubit>().close();
  }
  if (sl.isRegistered<SessionBloc>()) await sl<SessionBloc>().close();
  if (sl.isRegistered<SessionManager>()) await sl<SessionManager>().dispose();

  await sl.reset();
}
