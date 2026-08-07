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

  /// Where an unauthenticated launch lands. Wired in Slice 1.
  static const login = '/login';

  static const List<String> tabs = [trip, map, alerts, me];

  /// The tab index owning [location], or 0 when it belongs to none.
  static int tabIndexOf(String location) {
    final index = tabs.indexWhere((path) => location.startsWith(path));
    return index < 0 ? 0 : index;
  }
}
