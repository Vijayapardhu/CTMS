import 'package:equatable/equatable.dart';

/// Where the backend says the bus is.
///
/// Not the same thing as the device's own last fix. This one has been through
/// the server's plausibility gate and its road-snapping provider, which is why
/// the map prefers it: it is what everyone else — the office, the parents —
/// is looking at.
class LivePosition extends Equatable {
  const LivePosition({
    required this.latitude,
    required this.longitude,
    required this.isStale,
    this.recordedAt,
    this.ageSeconds,
  });

  final double latitude;
  final double longitude;

  /// The server's own judgement, from `ctms.gps.stale_after_seconds`. Never
  /// recomputed here — two definitions of "current" is one too many.
  final bool isStale;

  final DateTime? recordedAt;
  final int? ageSeconds;

  static LivePosition? fromJson(Object? json) {
    if (json is! Map<String, dynamic>) return null;

    final lat = _double(json['latitude']);
    final lng = _double(json['longitude']);
    if (lat == null || lng == null) return null;

    return LivePosition(
      latitude: lat,
      longitude: lng,
      isStale: json['is_stale'] == true,
      recordedAt: json['recorded_at'] is String
          ? DateTime.tryParse(json['recorded_at'] as String)
          : null,
      ageSeconds: _int(json['age_seconds']),
    );
  }

  @override
  List<Object?> get props => [latitude, longitude, isStale, recordedAt];
}

/// How far a stop has got. Mirrors the server's `StopProgressState`.
enum StopState {
  pending,
  approaching,
  arrived,
  departed,
  skipped,
  unknown;

  static StopState parse(Object? raw) => switch (raw) {
        'PENDING' => StopState.pending,
        'APPROACHING' => StopState.approaching,
        'ARRIVED' => StopState.arrived,
        'DEPARTED' => StopState.departed,
        'SKIPPED' => StopState.skipped,
        _ => StopState.unknown,
      };

  /// The bus has been and gone, or is not going.
  bool get isDone =>
      this == StopState.departed ||
      this == StopState.skipped ||
      this == StopState.arrived;
}

/// A stop as the live trip sees it: progress, not geography.
class LiveStop extends Equatable {
  const LiveStop({
    required this.stopId,
    required this.sequence,
    required this.state,
    this.name,
    this.etaAt,
    this.arrivedAt,
  });

  final String stopId;
  final int sequence;
  final StopState state;
  final String? name;
  final DateTime? etaAt;
  final DateTime? arrivedAt;

  static LiveStop fromJson(Map<String, dynamic> json) {
    return LiveStop(
      stopId: '${json['stop_id']}',
      sequence: _int(json['sequence_number']) ?? 0,
      state: StopState.parse(json['state']),
      name: json['stop_name'] as String?,
      etaAt: json['eta_at'] is String
          ? DateTime.tryParse(json['eta_at'] as String)
          : null,
      arrivedAt: json['arrived_at'] is String
          ? DateTime.tryParse(json['arrived_at'] as String)
          : null,
    );
  }

  @override
  List<Object?> get props => [stopId, sequence, state, etaAt, arrivedAt];
}

/// `GET /trips/{id}/live`.
class LiveTrip extends Equatable {
  const LiveTrip({
    required this.tripId,
    required this.status,
    required this.stops,
    this.position,
    this.occupied,
    this.capacity,
    this.delayMinutes,
  });

  final String tripId;
  final String status;
  final List<LiveStop> stops;
  final LivePosition? position;
  final int? occupied;
  final int? capacity;
  final int? delayMinutes;

  /// The first stop the bus has not finished with. What the driver is heading
  /// for, and the stop the ETA is asked about.
  LiveStop? get nextStop {
    for (final stop in stops) {
      if (!stop.state.isDone) return stop;
    }
    return null;
  }

  /// The stop the bus is at right now, when it is at one.
  LiveStop? get currentStop {
    for (final stop in stops) {
      if (stop.state == StopState.arrived) return stop;
    }
    return null;
  }

  static LiveTrip fromJson(Map<String, dynamic> json) {
    final stops = json['stops'];

    return LiveTrip(
      tripId: '${json['trip_id']}',
      status: '${json['status']}',
      position: LivePosition.fromJson(json['position']),
      occupied: _int((json['occupancy'] as Map<String, dynamic>?)?['occupied']),
      capacity: _int((json['occupancy'] as Map<String, dynamic>?)?['capacity']),
      delayMinutes: _int(json['delay_minutes']),
      stops: stops is List
          ? (stops
              .whereType<Map<String, dynamic>>()
              .map(LiveStop.fromJson)
              .toList()
            ..sort((a, b) => a.sequence.compareTo(b.sequence)))
          : const [],
    );
  }

  @override
  List<Object?> get props => [tripId, status, position, stops, delayMinutes];
}

/// How much to trust an ETA. The server's own word, not a guess made here.
enum EtaBasis {
  /// Computed from the bus's actual position through the Route Matrix.
  live,

  /// Computed, but from a position old enough that the server will not stand
  /// behind it.
  stale,

  /// The timetable. No live position has been received yet.
  scheduled,

  /// The bus is there.
  arrived,

  /// The server could not say.
  unknown;

  static EtaBasis parse(Object? raw) => switch (raw) {
        'live' => EtaBasis.live,
        'stale' => EtaBasis.stale,
        'scheduled' => EtaBasis.scheduled,
        'arrived' => EtaBasis.arrived,
        _ => EtaBasis.unknown,
      };
}

/// `GET /trips/{id}/eta?stop_id=…`
///
/// Always the server's number. The Route Matrix provider sits behind it, and
/// dividing a straight-line distance by an assumed speed in the client would
/// produce a different, worse answer that disagrees with the one the parents
/// are looking at.
class Eta extends Equatable {
  const Eta({
    required this.basis,
    this.etaAt,
    this.minutes,
    this.stopsAway,
  });

  final EtaBasis basis;
  final DateTime? etaAt;
  final int? minutes;
  final int? stopsAway;

  /// Whether this is worth showing as a time at all.
  bool get isUsable => minutes != null && basis != EtaBasis.unknown;

  static Eta fromJson(Map<String, dynamic> json) {
    return Eta(
      basis: EtaBasis.parse(json['basis']),
      etaAt: json['eta_at'] is String
          ? DateTime.tryParse(json['eta_at'] as String)
          : null,
      minutes: _int(json['minutes']),
      stopsAway: _int(json['stops_away']),
    );
  }

  @override
  List<Object?> get props => [basis, etaAt, minutes, stopsAway];
}

/// A stop's geography, from `GET /routes/{id}/stops`.
///
/// Separate from [LiveStop] on purpose: this is where the stop *is*, which
/// never changes during a trip, while [LiveStop] is how far the bus has got,
/// which changes constantly. Fetched once; polled never.
class RouteStop extends Equatable {
  const RouteStop({
    required this.id,
    required this.name,
    required this.sequence,
    required this.latitude,
    required this.longitude,
    this.address,
    this.landmark,
  });

  final String id;
  final String name;
  final int sequence;
  final double latitude;
  final double longitude;

  /// The backend's Geocoding provider already resolved this. The client never
  /// geocodes anything itself.
  final String? address;
  final String? landmark;

  static RouteStop? fromJson(Map<String, dynamic> json) {
    final lat = _double(json['latitude']);
    final lng = _double(json['longitude']);
    if (lat == null || lng == null) return null;

    return RouteStop(
      id: '${json['id']}',
      name: '${json['stop_name']}',
      sequence: _int(json['sequence_number']) ?? 0,
      latitude: lat,
      longitude: lng,
      address: json['address'] as String?,
      landmark: json['landmark'] as String?,
    );
  }

  @override
  List<Object?> get props => [id, sequence, latitude, longitude];
}

int? _int(Object? v) => switch (v) {
      int() => v,
      num() => v.toInt(),
      String() => int.tryParse(v),
      _ => null,
    };

double? _double(Object? v) => switch (v) {
      num() => v.toDouble(),
      String() => double.tryParse(v),
      _ => null,
    };
