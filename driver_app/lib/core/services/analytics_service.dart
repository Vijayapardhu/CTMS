/// Analytics boundary.
///
/// The event vocabulary is fixed by `docs/driver-app/09-screen-specifications.md`.
/// No event carries personally identifying information and none carries a
/// coordinate — a driver's route is their movements, and an analytics pipeline
/// is not a place to keep them.
abstract interface class AnalyticsService {
  Future<void> screenView(String name);
  Future<void> track(String event, {Map<String, Object?> properties});
}

class NoopAnalyticsService implements AnalyticsService {
  const NoopAnalyticsService();

  @override
  Future<void> screenView(String name) async {}

  @override
  Future<void> track(String event, {Map<String, Object?> properties = const {}}) async {}
}
