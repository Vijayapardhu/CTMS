import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/features/tracking/data/tracking_api.dart';
import 'package:ctms_driver/features/tracking/data/tracking_repository.dart';
import 'package:ctms_driver/features/tracking/domain/live_trip.dart';
import 'package:ctms_driver/features/tracking/presentation/bloc/tracking_bloc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fake_backend.dart';
import '../../helpers/test_doubles.dart';
import '../../helpers/trip_fixtures.dart';

/// The live map's data, against the real API client.
///
/// What is asserted here is mostly about honesty: that the server's judgement
/// of staleness is carried rather than recomputed, that a failed estimate does
/// not take the bus position down with it, and that a failed poll never blanks
/// a map the driver is navigating by.
void main() {
  late FakeBackend backend;
  late TrackingBloc bloc;

  setUp(() {
    backend = FakeBackend();

    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      retryDelays: const [],
    )..dio.httpClientAdapter = backend;

    bloc = TrackingBloc(
      repository: TrackingRepository(TrackingApi(client)),
      // No polling in tests; each poll is driven explicitly.
      interval: const Duration(days: 1),
    );
  });

  tearDown(() => bloc.close());

  Future<void> start() async {
    bloc.add(const TrackingStarted(tripId: 'trip-1', routeId: 'route-1'));
    for (var i = 0; i < 30; i++) {
      await Future<void>.delayed(Duration.zero);
    }
  }

  group('assembling the map', () {
    test('stops, live state and the estimate arrive together', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse(minutes: 4));

      await start();

      expect(bloc.state.stops, hasLength(3));
      expect(bloc.state.trip?.position?.latitude, 12.9716);
      expect(bloc.state.eta?.minutes, 4);
      expect(bloc.state.eta?.basis, EtaBasis.live);
    });

    test('the estimate is asked about the next unfinished stop', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on(
          '/live',
          status: 200,
          body: liveResponse(position: livePosition(), stops: [
            {
              'stop_id': 'stop-1',
              'stop_name': 'Stop 1',
              'sequence_number': 1,
              'state': 'DEPARTED',
              'eta_at': null,
              'arrived_at': '2026-08-09T07:50:00+00:00',
            },
            {
              'stop_id': 'stop-2',
              'stop_name': 'Stop 2',
              'sequence_number': 2,
              'state': 'PENDING',
              'eta_at': null,
              'arrived_at': null,
            },
          ]),
        )
        ..on('/eta', status: 200, body: etaResponse());

      await start();

      expect(bloc.state.trip?.nextStop?.stopId, 'stop-2');
      final request = backend.requests.lastWhere((r) => r.path.contains('/eta'));
      expect(request.query['stop_id'], 'stop-2');
    });

    test('stops are read once, not on every poll', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse())
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse());

      await start();
      bloc.add(const TrackingPolled());
      for (var i = 0; i < 30; i++) {
        await Future<void>.delayed(Duration.zero);
      }

      expect(
        backend.callsTo('/routes/route-1/stops'),
        1,
        reason: 'a stop does not move while the bus drives past it',
      );
      expect(backend.callsTo('/live'), 2);
    });
  });

  group('honesty', () {
    test('staleness is the server\'s judgement, carried not recomputed',
        () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on(
          '/live',
          status: 200,
          body: liveResponse(
            position: livePosition(isStale: true, ageSeconds: 400),
          ),
        )
        ..on('/eta', status: 200, body: etaResponse(basis: 'stale'));

      await start();

      expect(bloc.state.isStale, isTrue);
      expect(bloc.state.trip?.position?.ageSeconds, 400);
      expect(bloc.state.eta?.basis, EtaBasis.stale);
    });

    test('a failed estimate keeps the position', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..offline('/eta');

      await start();

      expect(bloc.state.trip?.position, isNotNull);
      expect(bloc.state.etaFailed, isTrue);
      expect(bloc.state.eta, isNull);
    });

    test('a failed route keeps the bus', () async {
      backend
        ..offline('/routes/route-1/stops')
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse());

      await start();

      expect(bloc.state.routeFailed, isTrue);
      expect(bloc.state.stops, isEmpty);
      expect(
        bloc.state.trip?.position,
        isNotNull,
        reason: 'a missing line is not a missing bus',
      );
    });

    test('a failed poll keeps the last map and marks it not current', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse());
      await start();
      expect(bloc.state.trip, isNotNull);

      backend.offline('/live');
      bloc.add(const TrackingPolled());
      for (var i = 0; i < 30; i++) {
        await Future<void>.delayed(Duration.zero);
      }

      expect(bloc.state.trip, isNotNull, reason: 'never blank a map mid-run');
      expect(bloc.state.isStalled, isTrue);
    });

    test('no position yet is not the same as a stale one', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on('/live', status: 200, body: liveResponse())
        ..on('/eta', status: 200, body: etaResponse(basis: 'scheduled', minutes: null));

      await start();

      expect(bloc.state.hasPosition, isFalse);
      expect(bloc.state.isStale, isFalse);
      expect(bloc.state.eta?.basis, EtaBasis.scheduled);
      expect(bloc.state.eta?.isUsable, isFalse);
    });
  });

  group('stopping', () {
    test('stopping clears everything', () async {
      backend
        ..on('/routes/route-1/stops', status: 200, body: routeStopsResponse())
        ..on('/live', status: 200, body: liveResponse(position: livePosition()))
        ..on('/eta', status: 200, body: etaResponse());
      await start();

      bloc.add(const TrackingStopped());
      await Future<void>.delayed(Duration.zero);

      expect(bloc.state.trip, isNull);
      expect(bloc.state.stops, isEmpty);
    });
  });
}
