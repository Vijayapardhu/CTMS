import '../../../core/api/api_failure.dart';
import '../domain/live_trip.dart';
import 'tracking_api.dart';

/// Everything the map needs for one poll.
class TrackingSnapshot {
  const TrackingSnapshot({
    required this.trip,
    this.eta,
    this.etaFailed = false,
  });

  final LiveTrip trip;

  /// Null when there is no next stop to ask about.
  final Eta? eta;

  /// The live state arrived but the estimate did not. Kept apart so the screen
  /// can show a real position with an honest "no estimate" beside it, rather
  /// than discarding both.
  final bool etaFailed;
}

/// Assembles the live map's data.
///
/// The stop geography is fetched once and held; the live state is polled. That
/// split is the whole reason this class exists — polling `/routes/{id}/stops`
/// every ten seconds would spend the driver's data on coordinates that cannot
/// have changed.
class TrackingRepository {
  TrackingRepository(this._api);

  final TrackingApi _api;

  final Map<String, List<RouteStop>> _stopsByRoute = {};

  /// Where the stops are. Cached per route for the life of the app.
  Future<List<RouteStop>> stopsFor(String routeId) async {
    final cached = _stopsByRoute[routeId];
    if (cached != null) return cached;

    final stops = await _api.stops(routeId);
    if (stops.isNotEmpty) _stopsByRoute[routeId] = stops;

    return stops;
  }

  /// One poll: the live trip, plus the estimate for whatever it is heading to.
  ///
  /// A failed estimate never sinks the poll. The bus's position is the more
  /// important of the two and is perfectly usable without a number beside it.
  Future<TrackingSnapshot> poll(String tripId) async {
    final trip = await _api.live(tripId);
    final next = trip.nextStop;

    if (next == null) return TrackingSnapshot(trip: trip);

    try {
      return TrackingSnapshot(
        trip: trip,
        eta: await _api.eta(tripId, stopId: next.stopId),
      );
    } on ApiFailure {
      return TrackingSnapshot(trip: trip, etaFailed: true);
    }
  }
}
