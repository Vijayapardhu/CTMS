/// `TripStatus` server-side.
///
/// UPPERCASE and compared as an enum, never as a string. An unrecognised value
/// becomes [unknown] rather than throwing: a status added server-side must not
/// crash a handset that has not been updated, and the screen degrades to
/// showing what it does know.
enum TripStatus {
  scheduled,
  running,
  completed,
  cancelled,
  unknown;

  static TripStatus fromJson(Object? value) => switch (value) {
        'SCHEDULED' => TripStatus.scheduled,
        'RUNNING' => TripStatus.running,
        'COMPLETED' => TripStatus.completed,
        'CANCELLED' => TripStatus.cancelled,
        _ => TripStatus.unknown,
      };

  bool get isClosed => this == completed || this == cancelled;
}

/// The route a trip runs.
///
/// Field names are the backend's own — `route_name`, not `name`. They were read
/// off a real response rather than guessed.
class TripRoute {
  const TripRoute({
    required this.id,
    required this.name,
    required this.code,
    this.stopCount,
    this.distanceKm,
    this.durationMinutes,
    this.startPoint,
    this.endPoint,
  });

  final String id;
  final String name;
  final String code;
  final int? stopCount;
  final double? distanceKm;
  final int? durationMinutes;
  final String? startPoint;
  final String? endPoint;

  /// What to put in front of a driver. The code is the operational handle; the
  /// name is often blank in real data, so it cannot be relied on alone.
  String get label => name.trim().isEmpty ? code : '$code · ${name.trim()}';

  static TripRoute fromJson(Map<String, dynamic> json) {
    return TripRoute(
      id: json['id'] as String? ?? '',
      name: json['route_name'] as String? ?? '',
      code: json['route_code'] as String? ?? '',
      stopCount: _int(json['number_of_stops']),
      distanceKm: _double(json['total_distance_km']),
      durationMinutes: _int(json['estimated_duration_minutes']),
      startPoint: json['start_point'] as String?,
      endPoint: json['end_point'] as String?,
    );
  }
}

/// The vehicle.
class TripBus {
  const TripBus({
    required this.id,
    required this.registrationNumber,
    this.seatingCapacity,
    this.model,
    this.status,
  });

  final String id;

  /// The largest thing on the trip card. It is how a driver identifies the bus
  /// in a yard of twenty.
  final String registrationNumber;
  final int? seatingCapacity;
  final String? model;
  final String? status;

  static TripBus fromJson(Map<String, dynamic> json) {
    return TripBus(
      id: json['id'] as String? ?? '',
      registrationNumber: json['registration_number'] as String? ?? '',
      seatingCapacity: _int(json['seating_capacity']),
      model: json['model'] as String?,
      status: json['status'] as String?,
    );
  }
}

/// One day's assignment.
class Trip {
  const Trip({
    required this.id,
    required this.status,
    required this.date,
    this.scheduledDeparture,
    this.scheduledArrival,
    this.actualDeparture,
    this.actualArrival,
    this.bookedSeatCount,
    this.occupiedSeatCount,
    this.cancellationReason,
    this.autoClosed = false,
    this.route,
    this.bus,
  });

  final String id;
  final TripStatus status;
  final DateTime? date;

  /// Wall-clock times as the backend sends them (`08:00:00`), not instants.
  /// A departure time is a time of day at the depot, and converting it through
  /// a device timezone is how 08:00 becomes 13:30.
  final String? scheduledDeparture;
  final String? scheduledArrival;
  final String? actualDeparture;
  final String? actualArrival;

  final int? bookedSeatCount;
  final int? occupiedSeatCount;

  /// Present when the trip was cancelled. Shown verbatim — it is written for
  /// the driver and is often the only explanation they get.
  final String? cancellationReason;

  /// The server closed this trip without the driver ending it (BR-261).
  final bool autoClosed;

  final TripRoute? route;
  final TripBus? bus;

  String? get busId => bus?.id;

  static Trip fromJson(Map<String, dynamic> json) {
    return Trip(
      id: json['id'] as String? ?? '',
      status: TripStatus.fromJson(json['status']),
      date: _date(json['trip_date']),
      scheduledDeparture: _time(json['scheduled_departure_time']),
      scheduledArrival: _time(json['scheduled_arrival_time']),
      actualDeparture: _time(json['actual_departure_time']),
      actualArrival: _time(json['actual_arrival_time']),
      bookedSeatCount: _int(json['booked_seat_count']),
      occupiedSeatCount: _int(json['occupied_seat_count']),
      cancellationReason: json['cancellation_reason'] as String?,
      autoClosed: json['auto_closed'] == true,
      route: json['route'] is Map<String, dynamic>
          ? TripRoute.fromJson(json['route'] as Map<String, dynamic>)
          : null,
      bus: json['bus'] is Map<String, dynamic>
          ? TripBus.fromJson(json['bus'] as Map<String, dynamic>)
          : null,
    );
  }
}

/// Whether the bus may carry children today.
///
/// `GET /buses/{id}/service-readiness` → `{ cleared, reasons[], inspection }`.
class ServiceReadiness {
  const ServiceReadiness({
    required this.cleared,
    required this.reasons,
    this.checkedAt,
    this.hasInspection = false,
  });

  final bool cleared;

  /// Every blocking reason at once, in the backend's own words. The API
  /// deliberately returns all of them rather than the first, so rendering one
  /// would defeat the design and leave a driver fixing them one round trip at
  /// a time.
  final List<String> reasons;

  /// When this answer was obtained, so an offline screen can say how old it is.
  final DateTime? checkedAt;

  final bool hasInspection;

  /// The one reason a driver can act on themselves is the missing inspection.
  /// Everything else belongs to operations. The split is what stops a driver
  /// completing an inspection and still being blocked with no idea why.
  static const _actionableMarker = 'inspection';

  List<String> get actionable => reasons
      .where((r) => r.toLowerCase().contains(_actionableMarker))
      .toList(growable: false);

  List<String> get blocking => reasons
      .where((r) => !r.toLowerCase().contains(_actionableMarker))
      .toList(growable: false);

  static ServiceReadiness fromJson(Map<String, dynamic> json, {DateTime? at}) {
    final reasons = json['reasons'];

    return ServiceReadiness(
      cleared: json['cleared'] == true,
      reasons: reasons is List
          ? reasons.map((r) => r.toString()).toList(growable: false)
          : const [],
      checkedAt: at,
      hasInspection: json['inspection'] != null,
    );
  }
}

int? _int(Object? value) => switch (value) {
      int() => value,
      num() => value.toInt(),
      String() => int.tryParse(value),
      _ => null,
    };

double? _double(Object? value) => switch (value) {
      num() => value.toDouble(),
      String() => double.tryParse(value),
      _ => null,
    };

DateTime? _date(Object? value) =>
    value is String ? DateTime.tryParse(value) : null;

/// Keeps `HH:mm` and drops the seconds the API sends.
String? _time(Object? value) {
  if (value is! String || value.isEmpty) return null;

  final parts = value.split(':');
  return parts.length >= 2 ? '${parts[0]}:${parts[1]}' : value;
}
