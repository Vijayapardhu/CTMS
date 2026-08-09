import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/api/api_failure.dart';
import 'package:ctms_driver/features/trip/data/trip_api.dart';
import 'package:ctms_driver/features/trip/data/trip_repository.dart';
import 'package:ctms_driver/features/trip/domain/trip_state.dart';
import 'package:ctms_driver/features/trip/presentation/bloc/trip_bloc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fake_backend.dart';
import '../../helpers/test_doubles.dart';
import '../../helpers/trip_fixtures.dart';

void main() {
  late FakeBackend backend;
  late TripBloc bloc;

  setUp(() {
    backend = FakeBackend();

    final client = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      retryDelays: const [],
    )..dio.httpClientAdapter = backend;

    bloc = TripBloc(
      repository: TripRepository(TripApi(client)),
      logger: SilentLogger(),
    );
  });

  tearDown(() => bloc.close());

  /// Drives one full read and returns the state it settles on.
  Future<TripState> load([TripEvent event = const TripRequested()]) async {
    bloc.add(event);
    return bloc.stream.firstWhere((s) => s is! TripLoading);
  }

  group('resolving the server answer into M1', () {
    test('starts in loading, holding nothing', () {
      expect(bloc.state, isA<TripLoading>());
      expect(bloc.state.trip, isNull);
    });

    test('an empty list is an answer — none, not unavailable', () async {
      backend.on('/trips', status: 200, body: tripsResponse(trips: []));

      final state = await load();

      expect(
        state,
        isA<TripNone>(),
        reason: 'the server was asked and said there is no trip today',
      );
    });

    test('a scheduled trip with a cleared bus is ready', () async {
      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      final state = await load();

      expect(state, isA<TripReady>());
      expect(state.trip!.bus!.registrationNumber, 'KA-05-MJ-3391');
      expect(state.readiness!.cleared, isTrue);
    });

    test('a scheduled trip with an uncleared bus is blocked, with reasons',
        () async {
      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(
              cleared: false,
              reasons: [missingInspection, expiredInsurance],
            ));

      final state = await load();

      expect(state, isA<TripBlocked>());
      expect(
        state.readiness!.reasons,
        [missingInspection, expiredInsurance],
        reason: 'the backend returns every blocking reason at once; showing '
            'one sends a driver round the loop twice',
      );
    });

    test('reasons split into what the driver can fix and what they cannot',
        () async {
      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness',
            status: 200,
            body: readinessResponse(
              cleared: false,
              reasons: [expiredInsurance, missingInspection],
            ));

      final state = await load();

      expect(state.readiness!.actionable, [missingInspection]);
      expect(state.readiness!.blocking, [expiredInsurance]);
    });

    test('a RUNNING trip resumes into running', () async {
      backend.on('/trips',
          status: 200,
          body: tripsResponse(trips: [tripJson(status: 'RUNNING', occupied: 26)]));

      final state = await load();

      expect(state, isA<TripRunning>());
      expect(state.trip!.occupiedSeatCount, 26);
      expect(
        backend.callsTo('/service-readiness'),
        0,
        reason: 'a running trip is past the gate; asking again spends a '
            'request to learn nothing',
      );
    });

    test('a COMPLETED trip is closed', () async {
      backend.on('/trips',
          status: 200,
          body: tripsResponse(trips: [tripJson(status: 'COMPLETED')]));

      expect(await load(), isA<TripClosed>());
    });

    test('a CANCELLED trip is closed and keeps the server reason', () async {
      backend.on('/trips',
          status: 200,
          body: tripsResponse(trips: [
            tripJson(
              status: 'CANCELLED',
              cancellationReason: 'Merged into the 08:10 service.',
              autoClosed: true,
            )
          ]));

      final state = await load();

      expect(state, isA<TripClosed>());
      expect(state.trip!.cancellationReason, 'Merged into the 08:10 service.');
      expect(state.trip!.autoClosed, isTrue);
    });

    test('a running trip wins over a scheduled one', () async {
      backend.on('/trips',
          status: 200,
          body: tripsResponse(trips: [
            tripJson(id: 'later', status: 'SCHEDULED', departure: '06:00:00'),
            tripJson(id: 'now', status: 'RUNNING'),
          ]));

      final state = await load();

      expect(state, isA<TripRunning>());
      expect(state.trip!.id, 'now');
    });

    test('an unreadable status does not crash the screen', () async {
      backend
        ..on('/trips',
            status: 200,
            body: tripsResponse(trips: [tripJson(status: 'TELEPORTED')]))
        ..on('/service-readiness',
            status: 200, body: readinessResponse(cleared: false));

      final state = await load();

      expect(
        state,
        isA<TripBlocked>(),
        reason: 'a status added server-side must not brick a handset that has '
            'not been updated — and unknown must never read as cleared',
      );
    });
  });

  group('first read fails with nothing held', () {
    test('a 500 gives unavailable, never none', () async {
      backend.on('/trips', status: 500, body: {
        'success': false,
        'message': 'Something went wrong on our side.',
        'data': null,
      });

      final state = await load();

      expect(state, isA<TripUnavailable>());
      expect(
        state,
        isNot(isA<TripNone>()),
        reason: 'none claims the server said there is no trip; here it said '
            'nothing at all',
      );
    });

    test('a dead network gives unavailable', () async {
      backend.offline('/trips');

      final state = await load();

      expect(state, isA<TripUnavailable>());
      expect((state as TripUnavailable).reason, isA<NetworkFailure>());
    });

    test('it does not sit in loading forever', () async {
      backend.offline('/trips');

      await load();

      expect(bloc.state, isNot(isA<TripLoading>()));
    });

    test('a 403 is unavailable and does not masquerade as no trip', () async {
      backend.on('/trips',
          status: 403,
          body: {'success': false, 'message': 'Forbidden.', 'data': null});

      final state = await load();

      expect(state, isA<TripUnavailable>());
      expect((state as TripUnavailable).reason, isA<ForbiddenFailure>());
    });

    test('a 401 is left to the session machine', () async {
      backend.on('/trips',
          status: 401,
          body: {'success': false, 'message': 'Unauthenticated.', 'data': null});

      bloc.add(const TripRequested());
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(
        bloc.state,
        isA<TripLoading>(),
        reason: 'the API client refreshes once and the session machine ends '
            'the session; a second opinion here would fight it',
      );
    });

    test('retrying after a failure reaches the real state', () async {
      backend.offline('/trips');
      expect(await load(), isA<TripUnavailable>());

      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      expect(await load(const TripRefreshed()), isA<TripReady>());
    });
  });

  group('a refresh fails with a trip already held', () {
    Future<void> loadReady() async {
      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());
      await load();
    }

    test('the trip stays on screen, marked stale', () async {
      await loadReady();
      backend.offline('/trips');

      bloc.add(const TripRefreshed());
      final state = await bloc.stream.first;

      expect(state, isA<TripReady>());
      expect(state.stale, isTrue);
      expect(state.failure, isA<NetworkFailure>());
      expect(
        state.trip!.bus!.registrationNumber,
        'KA-05-MJ-3391',
        reason: 'taking the day off the screen for the length of a tunnel is '
            'worse than showing it and saying it is old',
      );
    });

    test('it never falls back to unavailable', () async {
      await loadReady();
      backend.on('/trips', status: 500, body: {
        'success': false,
        'message': 'Something went wrong on our side.',
        'data': null,
      });

      bloc.add(const TripRefreshed());
      final state = await bloc.stream.first;

      expect(state, isNot(isA<TripUnavailable>()));
      expect(state.trip, isNotNull);
    });

    test('a successful refresh clears the stale mark', () async {
      await loadReady();
      backend.offline('/trips');
      bloc.add(const TripRefreshed());
      expect((await bloc.stream.first).stale, isTrue);

      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      final state = await load(const TripRefreshed());

      expect(state.stale, isFalse);
      expect(state.failure, isNull);
    });

    test('a refresh does not blank the screen with a skeleton', () async {
      await loadReady();

      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 200, body: readinessResponse());

      final seen = <TripState>[];
      final sub = bloc.stream.listen(seen.add);

      bloc.add(const TripRefreshed());
      await Future<void>.delayed(const Duration(milliseconds: 80));
      await sub.cancel();

      expect(
        seen.whereType<TripLoading>(),
        isEmpty,
        reason: 'pulling to refresh must not throw away the day\'s work while '
            'the request runs',
      );
    });
  });

  group('readiness is not allowed to sink the read', () {
    test('a failed readiness call still yields the trip, uncleared', () async {
      backend
        ..on('/trips', status: 200, body: tripsResponse())
        ..on('/service-readiness', status: 500, body: {
          'success': false,
          'message': 'Something went wrong on our side.',
          'data': null,
        });

      final state = await load();

      expect(state, isA<TripBlocked>());
      expect(
        state.readiness!.cleared,
        isFalse,
        reason: 'not knowing whether a bus is cleared is not the same as it '
            'being cleared',
      );
      expect(state.readiness!.reasons, isNotEmpty);
    });

    test('the endpoint is asked for the trip\'s own bus', () async {
      backend
        ..on('/trips',
            status: 200, body: tripsResponse(trips: [tripJson(busId: 'bus-77')]))
        ..on('/service-readiness', status: 200, body: readinessResponse());

      await load();

      expect(
        backend.requests.last.path,
        contains('/buses/bus-77/service-readiness'),
      );
    });
  });

  group('the request the client actually makes', () {
    test('asks for today, by date', () async {
      backend.on('/trips', status: 200, body: tripsResponse(trips: []));

      await load();

      final now = DateTime.now();
      final iso = '${now.year.toString().padLeft(4, '0')}-'
          '${now.month.toString().padLeft(2, '0')}-'
          '${now.day.toString().padLeft(2, '0')}';

      expect(backend.requests.first.path, contains('/trips'));
      expect(backend.requests.first.query['date'], iso);
    });

    test('is a GET, and sends no body', () async {
      backend.on('/trips', status: 200, body: tripsResponse(trips: []));

      await load();

      expect(backend.requests.first.method, 'GET');
      expect(backend.requests.first.body, isNull);
    });
  });
}
