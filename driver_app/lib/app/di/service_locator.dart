import 'dart:async';

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
import '../../core/sync/drift_sync_queue.dart';
import '../../core/sync/sync_cubit.dart';
import '../../core/sync/sync_database.dart';
import '../../core/sync/sync_engine.dart';
import '../../features/auth/data/auth_api.dart';
import '../../features/auth/data/session_manager.dart';
import '../../features/auth/data/session_store.dart';
import '../../features/auth/presentation/bloc/session_bloc.dart';
import '../../features/evidence/data/photo_capture.dart';
import '../../features/gps/data/location_source.dart';
import '../../features/gps/presentation/bloc/gps_cubit.dart';
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

/// Whether this graph built the sync database, and so should close it.
bool _ownsDatabase = false;

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
  LocationSource? locationSource,
  SyncDatabase? syncDatabase,
  Duration? syncGap,
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

  // ---- M6 and M3 --------------------------------------------------------
  //
  // The queue is the only thing between a fix and the server, so it is built
  // before anything that can enqueue. All app-scoped: a trip keeps being
  // tracked while the driver reads the map, and the queue outlives every
  // screen the work was started from.
  // Whoever created it closes it. A database handed in from outside — a test
  // holding an in-memory one — outlives this graph, and closing something we
  // were merely lent is how a second boot ends up querying a dead handle.
  _ownsDatabase = syncDatabase == null;
  final database = syncDatabase ?? SyncDatabase();
  final queue = DriftSyncQueue(database, sl<LoggerService>());
  final engine = SyncEngine(
    queue: queue,
    connectivity: sl<ConnectivityService>(),
    logger: sl<LoggerService>(),
    // The fourth thing a widget test must be able to flatten, for the same
    // reason as `retryDelays`: the replay throttle is real elapsed time, and a
    // test that sits through it is a wait, not a test. The throttle itself is
    // covered against a [SyncEngine] built directly.
    gap: syncGap ?? const Duration(seconds: 1),
    rateLimitPause: syncGap ?? const Duration(seconds: 60),
  );

  final positions = TripApi(sl<ApiClient>());
  engine.register(
    SyncKinds.position,
    (action) => positions.recordPosition(
      action.payload['trip_id']! as String,
      Map<String, Object?>.from(action.payload)..remove('trip_id'),
      idempotencyKey: action.idempotencyKey,
    ),
  );

  sl
    ..registerSingleton<SyncDatabase>(database)
    ..registerSingleton<DriftSyncQueue>(queue)
    ..registerSingleton<SyncEngine>(engine)
    ..registerSingleton<SyncCubit>(SyncCubit(
      queue: queue,
      engine: engine,
      connectivity: sl<ConnectivityService>(),
    ))
    ..registerSingleton<GpsCubit>(GpsCubit(
      source: locationSource ?? const GeolocatorSource(),
      queue: queue,
      sync: sl<SyncCubit>(),
      logger: sl<LoggerService>(),
    ));

  // Whatever survived the last run is owed before anything new is added.
  //
  // Not awaited: opening the queue is disk work, and boot has nothing to
  // decide from it. The banner appears when the read lands.
  unawaited(sl<SyncCubit>().refresh());
}

/// Clears every registration. Used between tests and on a full restart.
Future<void> resetDependencies() async {
  if (sl.isRegistered<GpsCubit>()) await sl<GpsCubit>().close();
  if (sl.isRegistered<SyncCubit>()) await sl<SyncCubit>().close();
  if (_ownsDatabase && sl.isRegistered<SyncDatabase>()) {
    await sl<SyncDatabase>().close();
  }
  if (sl.isRegistered<TripBloc>()) await sl<TripBloc>().close();
  if (sl.isRegistered<ConnectivityCubit>()) {
    await sl<ConnectivityCubit>().close();
  }
  if (sl.isRegistered<SessionBloc>()) await sl<SessionBloc>().close();
  if (sl.isRegistered<SessionManager>()) await sl<SessionManager>().dispose();

  await sl.reset();
}
