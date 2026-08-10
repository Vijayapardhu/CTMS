/// Every route path and name, declared once.
///
/// String literals scattered through call sites are how a typo becomes a
/// silent navigation failure, so nothing outside this file writes a path.
abstract final class Routes {
  /// The four roots from `docs/driver-app/03-screen-inventory.md`.
  ///
  /// R1 is one destination, not four: a driver on a route has a single trip,
  /// and splitting it across tabs would make them hunt for the boarding
  /// controls while people are queueing at the door.
  static const trip = '/trip';
  static const map = '/map';
  static const alerts = '/alerts';
  static const me = '/me';

  /// Session routes. Outside the shell — none of them has a tab bar, because
  /// there is nothing to navigate to until there is a session.
  /// Pushed over the trip tab, inside the shell — a driver mid-inspection
  /// keeps the tab bar and can still check the map.
  static const inspection = 'inspection';
  static const sos = 'sos';
  static const incident = 'incident';
  static const inspectionPath = '$trip/inspection/:busId';

  static const splash = '/splash';
  static const login = '/login';
  static const sessionExpired = '/session-expired';

  static const List<String> tabs = [trip, map, alerts, me];

  /// Routes reachable without a session. Everything not listed here requires
  /// one; the redirect denies by default rather than listing what to guard.
  static const Set<String> public = {splash, login, sessionExpired};

  static bool isPublic(String location) =>
      public.any((path) => location == path || location.startsWith('$path/'));

  /// The tab index owning [location], or 0 when it belongs to none.
  static int tabIndexOf(String location) {
    final index = tabs.indexWhere((path) => location.startsWith(path));
    return index < 0 ? 0 : index;
  }
}
