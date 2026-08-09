import 'dart:ui';

import 'package:ctms_driver/features/map/presentation/widgets/map_overlays.dart';
import 'package:ctms_driver/features/tracking/domain/live_trip.dart';
import 'package:ctms_driver/features/tracking/presentation/bloc/tracking_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

/// What the map draws, decided without rendering one.
///
/// A `GoogleMap` is an Android platform view with no implementation in the test
/// binding, so the decisions worth checking are made here instead: whether
/// there is a bus to draw at all, how faded it is, and whether there is enough
/// route to be a line.
void main() {
  RouteStop stop(int i) => RouteStop(
        id: 'stop-$i',
        name: 'Stop $i',
        sequence: i,
        latitude: 12.8 + i * 0.05,
        longitude: 77.4 + i * 0.05,
      );

  LiveStop progress(int i, StopState state) => LiveStop(
        stopId: 'stop-$i',
        sequence: i,
        state: state,
        name: 'Stop $i',
      );

  TrackingState stateWith({
    List<RouteStop> stops = const [],
    List<LiveStop> progressed = const [],
    bool stale = false,
    bool hasPosition = true,
  }) {
    return TrackingState(
      stops: stops,
      trip: LiveTrip(
        tripId: 'trip-1',
        status: 'RUNNING',
        stops: progressed,
        position: hasPosition
            ? LivePosition(latitude: 12.9, longitude: 77.5, isStale: stale)
            : null,
      ),
    );
  }

  group('the bus', () {
    test('is not drawn without a position', () {
      expect(
        MapOverlays.bus(stateWith(hasPosition: false), null, 'Your bus'),
        isNull,
        reason: 'a marker placed on a guess cannot be told from a real one',
      );
    });

    test('is solid when the position is current', () {
      final bus = MapOverlays.bus(
        stateWith(),
        const LatLng(12.9, 77.5),
        'Your bus',
      );

      expect(bus!.alpha, 1);
    });

    test('is faded when the server says the position is stale', () {
      final bus = MapOverlays.bus(
        stateWith(stale: true),
        const LatLng(12.9, 77.5),
        'Your bus',
      );

      expect(bus!.alpha, lessThan(1));
    });
  });

  group('the route', () {
    test('is drawn through every stop in order', () {
      final line = MapOverlays.route(
        stateWith(stops: [stop(1), stop(2), stop(3)]),
        const Color(0xFF0000FF),
      );

      expect(line.single.points, hasLength(3));
      expect(line.single.points.first.latitude, closeTo(12.85, 0.001));
    });

    test('one stop is not a line', () {
      expect(
        MapOverlays.route(stateWith(stops: [stop(1)]), const Color(0xFF0000FF)),
        isEmpty,
      );
    });

    test('no stops draws nothing rather than a default shape', () {
      expect(MapOverlays.route(stateWith(), const Color(0xFF0000FF)), isEmpty);
    });
  });

  group('stop markers', () {
    test('one per stop the route has', () {
      final markers = MapOverlays.stops(stateWith(stops: [stop(1), stop(2)]));

      expect(markers, hasLength(2));
      expect(markers.map((m) => m.markerId.value),
          containsAll(['stop-stop-1', 'stop-stop-2']));
    });

    test('are coloured by how far the bus has got', () {
      final state = stateWith(
        stops: [stop(1), stop(2), stop(3)],
        progressed: [
          progress(1, StopState.departed),
          progress(2, StopState.arrived),
          progress(3, StopState.pending),
        ],
      );

      expect(MapOverlays.stateOf(state.trip, 'stop-1'), StopState.departed);
      expect(MapOverlays.stateOf(state.trip, 'stop-2'), StopState.arrived);
      expect(MapOverlays.stateOf(state.trip, 'stop-3'), StopState.pending);
      expect(
        MapOverlays.hueFor(StopState.arrived),
        isNot(MapOverlays.hueFor(StopState.pending)),
        reason: 'the stop the bus is at must not look like one it has not '
            'reached',
      );
    });

    test('a stop the live trip says nothing about is not guessed at', () {
      expect(
        MapOverlays.stateOf(stateWith().trip, 'stop-99'),
        StopState.unknown,
      );
    });
  });
}
