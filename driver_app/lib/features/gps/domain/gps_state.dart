import 'package:equatable/equatable.dart';

/// One reading from the device.
///
/// [recordedAt] is the device's clock at the moment of the fix, not the moment
/// it was sent. A fix taken in a tunnel and posted twenty minutes later is a
/// position from twenty minutes ago, and sending it without saying so would put
/// the bus in the wrong place on someone's map.
class PositionFix extends Equatable {
  const PositionFix({
    required this.latitude,
    required this.longitude,
    required this.recordedAt,
    this.accuracyMeters,
    this.speedKmh,
    this.heading,
    this.altitudeMeters,
  });

  final double latitude;
  final double longitude;
  final DateTime recordedAt;
  final double? accuracyMeters;
  final double? speedKmh;
  final double? heading;
  final int? altitudeMeters;

  /// The request body, in the contract's field names.
  ///
  /// `idempotency_key` is added by the queue, not here: a fix does not own its
  /// key, the queued action does, and that key must survive every retry.
  Map<String, Object?> toJson() => {
        'latitude': latitude,
        'longitude': longitude,
        if (accuracyMeters != null) 'accuracy_meters': accuracyMeters,
        if (speedKmh != null) 'speed_kmh': speedKmh,
        if (heading != null) 'heading': heading,
        if (altitudeMeters != null) 'altitude_meters': altitudeMeters,
        'recorded_at': recordedAt.toUtc().toIso8601String(),
      };

  @override
  List<Object?> get props => [latitude, longitude, recordedAt];
}

/// M3 — the GPS stream.
///
/// Runs for the whole life of a running trip, independent of whichever screen
/// is visible. Never a dialog, never blocking, never a reason to stop the trip.
sealed class GpsState extends Equatable {
  const GpsState();

  /// Fixes taken but not yet accepted by the server.
  int get buffered => 0;

  @override
  List<Object?> get props => [buffered];
}

/// No trip running. The pill is hidden.
final class GpsIdle extends GpsState {
  const GpsIdle();
}

/// Waiting for the first fix.
final class GpsAcquiring extends GpsState {
  const GpsAcquiring();
}

/// Posting, and the server is taking them.
final class GpsLive extends GpsState {
  const GpsLive({this.lastFixAt});

  final DateTime? lastFixAt;

  @override
  List<Object?> get props => [lastFixAt];
}

/// Fixes are arriving but not landing. They accumulate; nothing is thrown away.
final class GpsBuffering extends GpsState {
  const GpsBuffering(this.count);

  final int count;

  @override
  int get buffered => count;
}

/// The device itself has no fix — a tunnel, or a basement car park. Also
/// accumulating, because the trip is still happening.
final class GpsNoSignal extends GpsState {
  const GpsNoSignal({this.count = 0});

  final int count;

  @override
  int get buffered => count;
}

/// Location is switched off or refused. E2: the trip cannot be tracked, and
/// saying so plainly beats a pill that quietly says "finding position" forever.
final class GpsDenied extends GpsState {
  const GpsDenied({required this.permanently});

  /// The driver chose "don't ask again", so only Settings can undo it.
  final bool permanently;

  @override
  List<Object?> get props => [permanently];
}
