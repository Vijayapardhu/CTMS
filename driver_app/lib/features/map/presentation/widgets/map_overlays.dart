import 'dart:ui';

import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../tracking/domain/live_trip.dart';
import '../../../tracking/presentation/bloc/tracking_bloc.dart';

/// What gets drawn on the map, as plain functions of the tracking state.
///
/// Separated from the widget deliberately. Deciding whether there is a bus to
/// draw, and how faded it should be, is the part that can be wrong in a way
/// that matters — and it is exactly the part a widget test cannot inspect
/// cheaply, because a `GoogleMap` is an Android platform view with no
/// implementation in the test binding.
abstract final class MapOverlays {
  /// Every stop the route has, coloured by how far the bus has got.
  static Set<Marker> stops(TrackingState state) {
    return {
      for (final stop in state.stops)
        Marker(
          markerId: MarkerId('stop-${stop.id}'),
          position: LatLng(stop.latitude, stop.longitude),
          icon: BitmapDescriptor.defaultMarkerWithHue(
            hueFor(stateOf(state.trip, stop.id)),
          ),
          infoWindow: InfoWindow(
            title: stop.name,
            snippet: stop.landmark ?? stop.address,
          ),
        ),
    };
  }

  /// The bus, or nothing.
  ///
  /// Nothing is the right answer before the first position: a marker placed on
  /// a guess is worse than an empty map, because the driver cannot tell the two
  /// apart once it is drawn.
  static Marker? bus(TrackingState state, LatLng? target, String label) {
    if (target == null) return null;

    return Marker(
      markerId: const MarkerId('bus'),
      position: target,
      zIndexInt: 2,
      // Faded when the server says the position is not current. A fresh-looking
      // bus over an old fix is the lie this screen most easily tells.
      alpha: state.isStale ? 0.45 : 1,
      icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueAzure),
      infoWindow: InfoWindow(title: label),
    );
  }

  /// The route.
  ///
  /// Drawn through the CTMS stop coordinates in sequence. The backend's routing
  /// provider does fetch a road-following polyline, but no endpoint exposes it,
  /// so this is the real geometry the API offers rather than a shape invented
  /// here. Fewer than two stops is not a line.
  static Set<Polyline> route(TrackingState state, Color colour) {
    if (state.stops.length < 2) return const {};

    return {
      Polyline(
        polylineId: const PolylineId('route'),
        points: [
          for (final stop in state.stops) LatLng(stop.latitude, stop.longitude),
        ],
        width: 6,
        color: colour,
      ),
    };
  }

  /// How far the bus has got with a given stop, from the live trip.
  static StopState stateOf(LiveTrip? trip, String stopId) {
    if (trip == null) return StopState.unknown;

    for (final stop in trip.stops) {
      if (stop.stopId == stopId) return stop.state;
    }
    return StopState.unknown;
  }

  static double hueFor(StopState state) => switch (state) {
        StopState.arrived => BitmapDescriptor.hueGreen,
        StopState.departed => BitmapDescriptor.hueViolet,
        StopState.skipped => BitmapDescriptor.hueRose,
        StopState.approaching => BitmapDescriptor.hueOrange,
        _ => BitmapDescriptor.hueYellow,
      };
}
