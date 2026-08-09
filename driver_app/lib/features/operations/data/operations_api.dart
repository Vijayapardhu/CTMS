import '../../../core/api/api_client.dart';

/// What the server says the bus is carrying.
class Occupancy {
  const Occupancy({this.occupied, this.capacity});

  final int? occupied;
  final int? capacity;

  static Occupancy fromJson(Object? json) {
    if (json is! Map<String, dynamic>) return const Occupancy();

    return Occupancy(
      occupied: json['occupied'] is num
          ? (json['occupied'] as num).toInt()
          : null,
      capacity: json['capacity'] is num
          ? (json['capacity'] as num).toInt()
          : null,
    );
  }

  /// From the whole envelope, which is what the sync engine hands back.
  static Occupancy fromEnvelope(Map<String, dynamic> body) =>
      fromJson(body['data']);
}

/// The four things a driver does to a trip while it is running.
///
/// Every one of them takes an idempotency key, because every one of them can be
/// queued: a boarding tapped in a dead spot must land exactly once when signal
/// returns, not once per retry.
class OperationsApi {
  const OperationsApi(this._client);

  final ApiClient _client;

  /// `POST /trips/{id}/board`
  ///
  /// `student_id` is deliberately omitted. The driver counts heads at the door;
  /// naming each student is the manifest flow, which is a different screen and
  /// a different slice.
  /// Returns the raw envelope so the sync engine can replay this method
  /// unchanged; [Occupancy.fromEnvelope] reads it.
  Future<Map<String, dynamic>> board(
    String tripId, {
    required String idempotencyKey,
    String? routeStopId,
  }) {
    return _client.post(
      '/trips/$tripId/board',
      body: {
        if (routeStopId != null) 'route_stop_id': routeStopId,
        'idempotency_key': idempotencyKey,
      },
    );
  }

  /// `POST /trips/{id}/alight`
  Future<Map<String, dynamic>> alight(
    String tripId, {
    required String idempotencyKey,
    String? routeStopId,
  }) {
    return _client.post(
      '/trips/$tripId/alight',
      body: {
        if (routeStopId != null) 'route_stop_id': routeStopId,
        'idempotency_key': idempotencyKey,
      },
    );
  }

  /// `POST /trips/{id}/stops/{stopId}/arrive`
  ///
  /// The manual fallback for BR-306: the geofence normally notices, but a
  /// driver standing at the stop knows better than a radius does.
  Future<Map<String, dynamic>> arrive(String tripId, String stopId) {
    return _client.post('/trips/$tripId/stops/$stopId/arrive');
  }

  /// `POST /trips/{id}/stops/{stopId}/skip`
  ///
  /// The reason is required by the server and shown to the students waiting
  /// there, so it is a sentence a driver would be willing to have read out.
  Future<Map<String, dynamic>> skip(
    String tripId,
    String stopId, {
    required String reason,
  }) {
    return _client.post(
      '/trips/$tripId/stops/$stopId/skip',
      body: {'reason': reason},
    );
  }

  /// `POST /trips/{id}/complete`
  Future<Map<String, dynamic>> complete(String tripId, {String? notes}) {
    return _client.post(
      '/trips/$tripId/complete',
      body: {if (notes != null && notes.isNotEmpty) 'notes': notes},
    );
  }
}
