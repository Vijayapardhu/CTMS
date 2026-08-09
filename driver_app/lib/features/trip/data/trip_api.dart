import '../../../core/api/api_client.dart';
import '../domain/trip.dart';

/// The trip endpoints the driver app calls.
///
/// Paths are the contract's own. Neither is constructed anywhere else in the
/// app, so there is one place to look when the contract moves.
class TripApi {
  const TripApi(this._client);

  final ApiClient _client;

  /// `GET /trips?date=…`
  ///
  /// Already scoped to the calling driver by the backend — there is no
  /// `driver_id` filter to pass, and passing one would suggest the scoping is
  /// the client's job.
  Future<List<Trip>> today({DateTime? on}) async {
    final date = (on ?? DateTime.now()).toLocal();
    final iso = '${date.year.toString().padLeft(4, '0')}-'
        '${date.month.toString().padLeft(2, '0')}-'
        '${date.day.toString().padLeft(2, '0')}';

    final body = await _client.get('/trips', query: {'date': iso});
    final data = body['data'];

    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(Trip.fromJson)
        .toList(growable: false);
  }

  /// `POST /trips/{id}/start`
  ///
  /// The gate, not a formality. A 409 here is the server declining for a
  /// stated reason and is the normal outcome of tapping Start too early or on
  /// an uncleared bus — it is handled, never retried.
  Future<Trip> start(String tripId) async {
    final body = await _client.post('/trips/$tripId/start');
    final data = body['data'];

    return Trip.fromJson(data is Map<String, dynamic> ? data : const {});
  }

  /// `GET /buses/{id}/service-readiness` → `{ cleared, reasons[], inspection }`
  Future<ServiceReadiness> readiness(String busId) async {
    final body = await _client.get('/buses/$busId/service-readiness');
    final data = body['data'];

    return ServiceReadiness.fromJson(
      data is Map<String, dynamic> ? data : const {},
      at: DateTime.now(),
    );
  }
}
