/// Payloads in the exact shape the backend returns.
///
/// Captured from a live `GET /trips?date=…` against the real Laravel API, not
/// invented — the field names in here are the ones that caught a guess:
/// `route_name` rather than `name`, `seating_capacity` rather than `capacity`.
library;

Map<String, dynamic> tripJson({
  String id = 'trip-1',
  String status = 'SCHEDULED',
  String busId = 'bus-1',
  String? departure = '08:00:00',
  String? arrival = '09:15:00',
  int? booked = 32,
  int? occupied = 0,
  String? cancellationReason,
  bool autoClosed = false,
  bool withRoute = true,
  bool withBus = true,
}) {
  return {
    'id': id,
    'schedule_id': 'schedule-1',
    'bus_id': busId,
    'driver_id': 'driver-1',
    'route_id': 'route-1',
    'trip_date': '2026-08-09T00:00:00.000000Z',
    'scheduled_departure_time': departure,
    'actual_departure_time': null,
    'scheduled_arrival_time': arrival,
    'actual_arrival_time': null,
    'status': status,
    'booked_seat_count': booked,
    'occupied_seat_count': occupied,
    'cancellation_reason': cancellationReason,
    'auto_closed': autoClosed,
    'created_at': '2026-08-01T00:00:00.000000Z',
    'updated_at': '2026-08-01T00:00:00.000000Z',
    if (withRoute)
      'route': {
        'id': 'route-1',
        'route_name': 'Morning — North Campus',
        'route_code': 'RT-5167',
        'total_distance_km': '18.40',
        'estimated_duration_minutes': 75,
        'status': 'ACTIVE',
        'start_point': 'Depot',
        'end_point': 'North Campus',
        'number_of_stops': 14,
      },
    if (withBus)
      'bus': {
        'id': busId,
        'registration_number': 'KA-05-MJ-3391',
        'vehicle_name': 'Bus 12',
        'model': 'Tata Starbus',
        'seating_capacity': 40,
        'status': 'ACTIVE',
        'current_odometer': 45120,
      },
  };
}

/// The `/trips` envelope, including the pagination block the API adds.
Map<String, dynamic> tripsResponse({List<Map<String, dynamic>>? trips}) {
  final items = trips ?? [tripJson()];

  return {
    'success': true,
    'message': 'Trips retrieved successfully.',
    'code': 200,
    'data': items,
    'pagination': {
      'total': items.length,
      'per_page': 15,
      'current_page': 1,
      'last_page': 1,
    },
  };
}

/// `GET /buses/{id}/service-readiness`.
Map<String, dynamic> readinessResponse({
  bool cleared = true,
  List<String> reasons = const [],
  bool withInspection = true,
}) {
  return {
    'success': true,
    'message': 'Service readiness retrieved successfully.',
    'code': 200,
    'data': {
      'cleared': cleared,
      'reasons': reasons,
      'inspection': withInspection && cleared
          ? {'id': 'inspection-1', 'outcome': 'PASSED'}
          : null,
    },
  };
}

/// `POST /trips/{id}/start` on success — the trip row, now RUNNING.
Map<String, dynamic> startResponse({Map<String, dynamic>? trip}) {
  return {
    'success': true,
    'message': 'Trip started. Passengers have been notified.',
    'code': 200,
    'data': trip ?? tripJson(status: 'RUNNING', departure: '08:00:00'),
  };
}

/// A 409 from the start gate.
///
/// The shapes are the ones `TripService::start` and `ApiError::response`
/// actually produce: a single sentence, plus whatever `context` the rule
/// attached. Only the clearance gate returns a `reasons` array; every other
/// rule refuses one at a time, which is why the client must render both.
Map<String, dynamic> startRefusal({
  required String message,
  Map<String, dynamic>? errors,
}) {
  return {
    'success': false,
    'message': message,
    'data': null,
    'errors': errors,
    'code': 409,
  };
}

/// BR-252 — too early. The one refusal that resolves itself, and the only one
/// carrying `scheduled_departure`.
Map<String, dynamic> tooEarlyRefusal({String at = '07:45'}) => startRefusal(
      message: 'This trip cannot start until $at.',
      errors: {'scheduled_departure': '2026-08-09T08:00:00+00:00'},
    );

/// BR-251 — the composite safety gate, the one refusal that reports every
/// blocking reason at once.
Map<String, dynamic> notClearedRefusal(List<String> reasons) => startRefusal(
      message: 'This bus is not cleared for service: ${reasons.join(' ')}',
      errors: {'reasons': reasons},
    );

/// `POST /trips/{id}/positions`.
///
/// [data] is null when the server has already seen this idempotency key — it
/// answers 200 with an empty payload and the message "This position was already
/// recorded". That is the replay mechanism working, not a conflict.
Map<String, dynamic> positionResponse({Object? data = _recorded}) {
  return {
    'success': true,
    'message': 'Position recorded.',
    'code': 201,
    'data': data == _recorded
        ? {
            'id': 'loc-1',
            'trip_id': 'trip-1',
            'latitude': '12.9716000',
            'longitude': '77.5946000',
            'recorded_at': '2026-08-09T08:00:00.000000Z',
          }
        : data,
  };
}

const _recorded = Object();

/// A 409 from the ingestion pipeline's plausibility gate. The `reason` key is
/// what separates it from the trip-not-running refusal, which carries none.
Map<String, dynamic> positionRejected(String reason) {
  return {
    'success': false,
    'message': 'Position rejected: $reason',
    'data': null,
    'errors': {'reason': reason},
    'code': 409,
  };
}

/// `GET /routes/{id}/stops` — captured from the live API.
Map<String, dynamic> routeStopsResponse({int count = 3}) {
  return {
    'success': true,
    'message': 'Route stops retrieved successfully.',
    'code': 200,
    'data': [
      for (var i = 1; i <= count; i++)
        {
          'id': 'stop-$i',
          'route_id': 'route-1',
          'stop_name': 'Stop $i',
          'sequence_number': i,
          'latitude': 12.80 + (i * 0.05),
          'longitude': 77.42 + (i * 0.05),
          'address': '$i Some Road, Bengaluru',
          'landmark': 'Landmark $i',
          'distance_from_start_km': i * 6,
          'estimated_arrival_minutes': i * 10,
          'waiting_time_minutes': 5,
          'stop_type': 'BOTH',
        },
    ],
  };
}

/// `GET /trips/{id}/live` — the shape the tracking controller returns.
Map<String, dynamic> liveResponse({
  Map<String, dynamic>? position,
  List<Map<String, dynamic>>? stops,
  String status = 'RUNNING',
  int? delayMinutes = 0,
}) {
  return {
    'success': true,
    'message': 'Live trip state retrieved successfully.',
    'code': 200,
    'data': {
      'trip_id': 'trip-1',
      'status': status,
      'position': position,
      'occupancy': {'occupied': 0, 'capacity': 40},
      'delay_minutes': delayMinutes,
      'stops': stops ??
          [
            for (var i = 1; i <= 3; i++)
              {
                'stop_id': 'stop-$i',
                'stop_name': 'Stop $i',
                'sequence_number': i,
                'state': 'PENDING',
                'eta_at': null,
                'arrived_at': null,
              },
          ],
    },
  };
}

/// A position block for [liveResponse]. `is_stale` is the server's own
/// judgement and is never recomputed on the client.
Map<String, dynamic> livePosition({
  double latitude = 12.9716,
  double longitude = 77.5946,
  bool isStale = false,
  int ageSeconds = 12,
}) {
  return {
    'latitude': latitude,
    'longitude': longitude,
    'recorded_at': '2026-08-09T08:00:00+00:00',
    'age_seconds': ageSeconds,
    'is_stale': isStale,
  };
}

/// `GET /trips/{id}/eta?stop_id=…`
Map<String, dynamic> etaResponse({
  String basis = 'live',
  int? minutes = 4,
  int? stopsAway = 1,
}) {
  return {
    'success': true,
    'message': 'Estimate retrieved successfully.',
    'code': 200,
    'data': {
      // Relative to now, as the server's would be: an absolute timestamp baked
      // into a fixture is in the past by the time anyone runs it, and the
      // client would rightly render that as "arriving now".
      'eta_at': minutes == null
          ? null
          : DateTime.now()
              .toUtc()
              .add(Duration(minutes: minutes))
              .toIso8601String(),
      'minutes': minutes,
      'basis': basis,
      'stops_away': stopsAway,
    },
  };
}

/// The reason string the backend actually returns for a missing inspection —
/// the one and only reason a driver can act on themselves.
const missingInspection = 'No pre-trip inspection has been completed today.';

/// An operations-owned reason, for checking the two-group split.
const expiredInsurance = 'Insurance is missing or expired.';
