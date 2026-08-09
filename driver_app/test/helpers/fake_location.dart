import 'dart:async';

import 'package:ctms_driver/features/gps/data/location_source.dart';
import 'package:ctms_driver/features/gps/domain/gps_state.dart';

/// A position stream a test can drive.
///
/// The whole of M3 — buffering, replay, the pill, what happens when the trip
/// closes underneath it — is logic that must be provable without a satellite,
/// so the only thing substituted is the hardware.
class FakeLocation implements LocationSource {
  FakeLocation({this.access = LocationAccess.granted});

  LocationAccess access;

  final _controller = StreamController<PositionFix>.broadcast();

  /// Fixes handed to the stream so far.
  int emitted = 0;

  @override
  Future<LocationAccess> ensureAccess() async => access;

  @override
  Stream<PositionFix> watch() => _controller.stream;

  /// Produces one fix. Coordinates default to somewhere plausible; nothing in
  /// the client cares what they are, because plausibility is the server's job.
  void emit({
    double latitude = 12.9716,
    double longitude = 77.5946,
    DateTime? at,
  }) {
    emitted++;
    _controller.add(PositionFix(
      latitude: latitude,
      longitude: longitude,
      recordedAt: at ?? DateTime.utc(2026, 8, 9, 8, emitted % 60),
      accuracyMeters: 8,
      speedKmh: 32,
      heading: 90,
      altitudeMeters: 920,
    ));
  }

  void fail(Object error) => _controller.addError(error);

  Future<void> dispose() => _controller.close();
}
