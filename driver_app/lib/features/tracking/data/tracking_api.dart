import '../../../core/api/api_client.dart';
import '../domain/live_trip.dart';

/// The three reads the live map is built from.
///
/// All of them are CTMS endpoints. The client never talks to Google: routing,
/// road snapping, the Route Matrix and geocoding all sit behind these, which is
/// where the credentials, the quota and the fallback providers live.
class TrackingApi {
  const TrackingApi(this._client);

  final ApiClient _client;

  /// `GET /trips/{id}/live` — the authoritative trip state, polled.
  Future<LiveTrip> live(String tripId) async {
    final body = await _client.get('/trips/$tripId/live');
    final data = body['data'];

    return LiveTrip.fromJson(data is Map<String, dynamic> ? data : const {});
  }

  /// `GET /trips/{id}/eta?stop_id=…`
  ///
  /// The stop is always named. A driver has no `pickup_stop_id`, so the
  /// server's default — a student's own stop — does not exist for them, and
  /// omitting it is a 422.
  Future<Eta> eta(String tripId, {required String stopId}) async {
    final body = await _client.get(
      '/trips/$tripId/eta',
      query: {'stop_id': stopId},
    );
    final data = body['data'];

    return Eta.fromJson(data is Map<String, dynamic> ? data : const {});
  }

  /// `GET /routes/{id}/stops` — where the stops are.
  ///
  /// Read once per trip: a stop does not move while the bus drives past it.
  Future<List<RouteStop>> stops(String routeId) async {
    final body = await _client.get('/routes/$routeId/stops');
    final data = body['data'];

    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(RouteStop.fromJson)
        .whereType<RouteStop>()
        .toList(growable: false)
      ..sort((a, b) => a.sequence.compareTo(b.sequence));
  }
}
