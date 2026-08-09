import 'dart:math' as math;

/// How close the bus is to a stop.
///
/// Computed on the device from the fix the device already has. This is only
/// ever used to decide what the driver is *offered* — the server still owns
/// whether an arrival is accepted, and its own geofence still runs. A phone
/// deciding it has arrived would be a phone deciding a bus is somewhere.
class StopProximity {
  const StopProximity({required this.metres, required this.withinRadius});

  final double metres;

  /// Inside the radius a driver can reasonably be said to be *at* the stop.
  final bool withinRadius;

  /// Roughly a bus length either side of a stop plus normal GPS error in a
  /// street with buildings. Tight enough that the next stop down the road does
  /// not also match, loose enough that a driver pulled up on the far kerb does.
  static const radiusMetres = 100.0;

  static StopProximity between({
    required double fromLatitude,
    required double fromLongitude,
    required double toLatitude,
    required double toLongitude,
    double radius = radiusMetres,
  }) {
    final metres = _haversine(
      fromLatitude,
      fromLongitude,
      toLatitude,
      toLongitude,
    );

    return StopProximity(metres: metres, withinRadius: metres <= radius);
  }

  /// Great-circle distance. Accurate to well under a metre at these ranges,
  /// which is far better than the fix it is measuring.
  static double _haversine(double lat1, double lon1, double lat2, double lon2) {
    const earthRadius = 6371000.0;

    final dLat = _radians(lat2 - lat1);
    final dLon = _radians(lon2 - lon1);

    final a = math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(_radians(lat1)) *
            math.cos(_radians(lat2)) *
            math.sin(dLon / 2) *
            math.sin(dLon / 2);

    return earthRadius * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a));
  }

  static double _radians(double degrees) => degrees * math.pi / 180;

  /// A distance a driver can read at a glance.
  String get readable =>
      metres < 1000 ? '${metres.round()} m' : '${(metres / 1000).toStringAsFixed(1)} km';
}
