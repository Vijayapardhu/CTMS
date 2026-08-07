import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/services/analytics_service.dart';
import '../../core/widgets/error_boundary.dart';
import '../../features/alerts/presentation/alerts_screen.dart';
import '../../features/map/presentation/map_screen.dart';
import '../../features/profile/presentation/profile_screen.dart';
import '../../features/trip/presentation/trip_screen.dart';
import '../di/service_locator.dart';
import 'app_shell.dart';
import 'routes.dart';

/// Builds the router.
///
/// A [StatefulShellRoute] rather than four independent stacks: each tab keeps
/// its own history, so a driver who checks the map mid-inspection returns to
/// the same place rather than to the top.
///
/// No redirect is installed in Slice 0. Authentication gating is a Slice 1
/// concern, and a stub redirect here now would be a guess at its shape.
GoRouter buildRouter({GlobalKey<NavigatorState>? navigatorKey}) {
  final analytics = sl<AnalyticsService>();

  return GoRouter(
    navigatorKey: navigatorKey ?? sl<GlobalKey<NavigatorState>>(),
    initialLocation: Routes.trip,
    observers: [_AnalyticsObserver(analytics)],
    errorBuilder: (context, state) => const AppErrorView(),
    routes: [
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            AppShell(navigationShell: navigationShell),
        branches: [
          _branch(Routes.trip, const TripScreen()),
          _branch(Routes.map, const MapScreen()),
          _branch(Routes.alerts, const AlertsScreen()),
          _branch(Routes.me, const ProfileScreen()),
        ],
      ),
    ],
  );
}

StatefulShellBranch _branch(String path, Widget screen) {
  return StatefulShellBranch(
    routes: [
      GoRoute(
        path: path,
        name: path,
        builder: (context, state) => screen,
      ),
    ],
  );
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
