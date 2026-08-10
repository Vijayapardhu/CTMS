import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../core/services/analytics_service.dart';
import '../../core/services/logger_service.dart';
import '../../core/widgets/error_boundary.dart';
import '../../features/alerts/presentation/alerts_screen.dart';
import '../../features/auth/domain/session_state.dart';
import '../../features/auth/presentation/bloc/session_bloc.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/session_expired_screen.dart';
import '../../features/auth/presentation/splash_screen.dart';
import '../../core/api/api_client.dart';
import '../../core/sync/drift_sync_queue.dart';
import '../../core/sync/sync_cubit.dart';
import '../../features/incidents/data/incident_api.dart';
import '../../features/incidents/presentation/bloc/incident_cubit.dart';
import '../../features/incidents/presentation/incident_screen.dart';
import '../../features/incidents/presentation/sos_screen.dart';
import '../../features/inspection/presentation/inspection_entry.dart';
import '../../features/map/presentation/map_screen.dart';
import '../../features/profile/presentation/profile_screen.dart';
import '../../features/trip/presentation/trip_screen.dart';
import '../di/service_locator.dart';
import 'app_shell.dart';
import 'debug_navigation_observer.dart';
import 'routes.dart';

/// Builds the router.
///
/// A [StatefulShellRoute] rather than four independent stacks: each tab keeps
/// its own history, so a driver who checks the map mid-inspection returns to
/// the same place rather than to the top.
///
/// The session routes sit **outside** the shell. A driver being signed out
/// mid-trip must not land on a login screen with a tab bar offering to take
/// them back to a trip they can no longer load.
GoRouter buildRouter({
  required SessionBloc session,
  GlobalKey<NavigatorState>? navigatorKey,
}) {
  final analytics = sl<AnalyticsService>();

  return GoRouter(
    navigatorKey: navigatorKey ?? sl<GlobalKey<NavigatorState>>(),
    initialLocation: Routes.splash,
    refreshListenable: _BlocRefresh(session),
    redirect: (context, state) => _redirect(session.state, state.matchedLocation),
    observers: [
      _AnalyticsObserver(analytics),
      ...DebugNavigationObserver.attachIfDebug(sl<LoggerService>()),
    ],
    errorBuilder: (context, state) => const AppErrorView(),
    routes: [
      GoRoute(
        path: Routes.splash,
        name: Routes.splash,
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: Routes.login,
        name: Routes.login,
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: Routes.sessionExpired,
        name: Routes.sessionExpired,
        builder: (context, state) {
          final current = session.state;

          return SessionExpiredScreen(
            reason: current is SessionExpired
                ? current.reason
                : SessionEndReason.refreshRefused,
            serverMessage: current is SessionExpired ? current.message : null,
          );
        },
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.trip,
                name: Routes.trip,
                builder: (context, state) => const TripScreen(),
                routes: [
                  GoRoute(
                    path: 'sos',
                    name: Routes.sos,
                    builder: (context, state) => const SosScreen(),
                  ),
                  GoRoute(
                    path: 'incident',
                    name: Routes.incident,
                    builder: (context, state) => BlocProvider<IncidentCubit>(
                      create: (_) => IncidentCubit(
                        api: IncidentApi(sl<ApiClient>()),
                        queue: sl<DriftSyncQueue>(),
                        sync: sl<SyncCubit>(),
                      )..load(),
                      child: const IncidentScreen(),
                    ),
                  ),
                  GoRoute(
                    path: 'inspection/:busId',
                    name: Routes.inspection,
                    builder: (context, state) => InspectionEntry(
                      busId: state.pathParameters['busId'] ?? '',
                      minimumOdometer:
                          int.tryParse(state.uri.queryParameters['min'] ?? ''),
                      busLabel: state.uri.queryParameters['bus'],
                    ),
                  ),
                ],
              ),
            ],
          ),
          _branch(Routes.map, const MapScreen()),
          _branch(Routes.alerts, const AlertsScreen()),
          _branch(Routes.me, const ProfileScreen()),
        ],
      ),
    ],
  );
}

/// Where the driver belongs, given the session.
///
/// Deny by default: anything not in [Routes.public] needs a session. A new
/// route added without thinking about auth lands behind the gate, which is the
/// safe direction to be wrong in.
///
/// Returns null when the current location is already correct — returning a
/// location unconditionally makes go_router loop.
@visibleForTesting
String? redirectFor(SessionState session, String location) =>
    _redirect(session, location);

String? _redirect(SessionState session, String location) {
  final isPublic = Routes.isPublic(location);

  return switch (session) {
    // Still reading storage. Hold the splash rather than flashing a login
    // screen at a driver who is in fact signed in.
    SessionInitialising() =>
      location == Routes.splash ? null : Routes.splash,

    SessionExpired() =>
      location == Routes.sessionExpired ? null : Routes.sessionExpired,

    SessionUnauthenticated() ||
    SessionAuthenticating() ||
    SessionLoginFailed() =>
      location == Routes.login ? null : Routes.login,

    // Signed in — including from cache, and including mid-refresh. A refresh
    // must never bounce a driver out of the screen they are working in.
    SessionAuthenticated() || SessionOffline() || SessionRefreshing() =>
      isPublic ? Routes.trip : null,
  };
}

StatefulShellBranch _branch(String path, Widget screen) {
  return StatefulShellBranch(
    routes: [
      GoRoute(path: path, name: path, builder: (context, state) => screen),
    ],
  );
}

/// Bridges the bloc's stream to `refreshListenable`, so the redirect re-runs
/// the moment the session changes rather than on the next navigation.
class _BlocRefresh extends ChangeNotifier {
  _BlocRefresh(SessionBloc bloc) {
    _subscription = bloc.stream.listen((_) => notifyListeners());
  }

  late final StreamSubscription<SessionState> _subscription;

  @override
  void dispose() {
    _subscription.cancel();
    super.dispose();
  }
}

/// Reports screen views.
///
/// The route name only — never a query string, which is where an identifier
/// would leak into analytics.
class _AnalyticsObserver extends NavigatorObserver {
  _AnalyticsObserver(this._analytics);

  final AnalyticsService _analytics;

  void _report(Route<dynamic>? route) {
    final name = route?.settings.name;
    if (name != null) _analytics.screenView(name);
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _report(route);

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _report(previousRoute);

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) =>
      _report(newRoute);
}
