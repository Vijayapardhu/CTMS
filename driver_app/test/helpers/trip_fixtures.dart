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

/// The reason string the backend actually returns for a missing inspection —
/// the one and only reason a driver can act on themselves.
const missingInspection = 'No pre-trip inspection has been completed today.';

/// An operations-owned reason, for checking the two-group split.
const expiredInsurance = 'Insurance is missing or expired.';
