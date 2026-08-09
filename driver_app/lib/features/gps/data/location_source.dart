import 'dart:async';

import 'package:geolocator/geolocator.dart';

import '../domain/gps_state.dart';

/// Whether the app may read location, and why not when it may not.
enum LocationAccess { granted, denied, deniedForever, serviceOff }

/// The device's position stream.
///
/// An interface because a widget test cannot produce a satellite fix, and
/// because the whole of M3 — buffering, replay, the pill — is logic that must
/// be testable without one.
abstract interface class LocationSource {
  Future<LocationAccess> ensureAccess();

  /// Fixes, as they arrive. Closing the subscription stops the hardware.
  Stream<PositionFix> watch();
}

class GeolocatorSource implements LocationSource {
  const GeolocatorSource();

  /// Distance filter and accuracy per M3: a fix every 5–10 seconds while
  /// moving is what the stream is specified to post, and asking the hardware
  /// for more would spend battery on readings the server rate-limits away.
  static final _settings = AndroidSettings(
    accuracy: LocationAccuracy.high,
    distanceFilter: 10,
    intervalDuration: Duration(seconds: 5),
    foregroundNotificationConfig: ForegroundNotificationConfig(
      notificationTitle: 'Trip in progress',
      notificationText: 'Sharing the bus position with the transport office.',
      enableWakeLock: true,
    ),
  );

  @override
  Future<LocationAccess> ensureAccess() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return LocationAccess.serviceOff;
    }

    var permission = await Geolocator.checkPermission();

    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    return switch (permission) {
      LocationPermission.always ||
      LocationPermission.whileInUse =>
        LocationAccess.granted,
      LocationPermission.deniedForever => LocationAccess.deniedForever,
      _ => LocationAccess.denied,
    };
  }

  @override
  Stream<PositionFix> watch() {
    return Geolocator.getPositionStream(locationSettings: _settings).map(
      (p) => PositionFix(
        latitude: p.latitude,
        longitude: p.longitude,
        // The device's own timestamp, so a fix replayed after a tunnel still
        // says when it was actually taken.
        recordedAt: p.timestamp,
        accuracyMeters: p.accuracy,
        // The platform reports m/s; the contract is km/h.
        speedKmh: p.speed * 3.6,
        heading: p.heading,
        altitudeMeters: p.altitude.round(),
      ),
    );
  }
}
