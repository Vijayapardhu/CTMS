import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/api/api_failure.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/auth_fixtures.dart';
import '../helpers/fake_backend.dart';
import '../helpers/test_doubles.dart';

/// Counts what the client reports, so the rule can be checked call by call.
class _RecordingConnectivity implements ConnectivityService {
  final List<bool> reports = [];
  Reachability _current = Reachability.online;

  int get failures => reports.where((ok) => !ok).length;
  int get successes => reports.where((ok) => ok).length;

  @override
  Stream<Reachability> get changes => const Stream.empty();

  @override
  Reachability get current => _current;

  @override
  void recordFailure() {
    reports.add(false);
    if (failures >= 3) _current = Reachability.offline;
  }

  @override
  void recordSuccess() {
    reports.add(true);
    _current = Reachability.online;
  }
}

void main() {
  late FakeBackend backend;
  late _RecordingConnectivity connectivity;
  late ApiClient client;

  ApiClient build() {
    final api = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      logger: SilentLogger(),
      connectivity: connectivity,
      // No back-off: these tests are about what gets reported, not about how
      // long the client waits before reporting it.
      retryDelays: const [],
    );
    api.dio.httpClientAdapter = backend;
    return api;
  }

  setUp(() {
    backend = FakeBackend();
    connectivity = _RecordingConnectivity();
    client = build();
  });

  group('what the client reports to M7', () {
    test('a successful response proves the API is reachable', () async {
      backend.on('/auth/me', status: 200, body: meResponseBody());

      await client.get('/auth/me', bearer: 'token');

      expect(connectivity.reports, [true]);
      expect(connectivity.current, Reachability.online);
    });

    test('a 4xx proves it too — the server answered', () async {
      backend.on('/trips/1', status: 403, body: errorBody('Not your trip.'));

      await expectLater(
        client.get('/trips/1', bearer: 'token'),
        throwsA(isA<ForbiddenFailure>()),
      );

      expect(
        connectivity.reports,
        [true],
        reason: 'telling a driver they are offline because they were refused '
            'a trip would be a lie about the one thing the banner reports',
      );
      expect(connectivity.failures, 0);
    });

    test('a 409 does not count against reachability', () async {
      backend.on('/trips/1/board',
          status: 409, body: errorBody('The bus is full (40/40).'));

      await expectLater(
        client.post('/trips/1/board', bearer: 'token'),
        throwsA(isA<ConflictFailure>()),
      );

      expect(connectivity.failures, 0);
    });

    test('a 422 does not count against reachability', () async {
      backend.on('/trips/1/board',
          status: 422, body: errorBody('Please check what you entered.'));

      await expectLater(
        client.post('/trips/1/board', bearer: 'token'),
        throwsA(isA<ValidationFailure>()),
      );

      expect(connectivity.failures, 0);
    });

    test('a 5xx is a reachability failure', () async {
      backend.on('/auth/me', status: 500, body: errorBody('Server error.'));

      await expectLater(
        client.get('/auth/me', bearer: 'token'),
        throwsA(isA<ServerFailure>()),
      );

      expect(connectivity.successes, 0);
      expect(connectivity.failures, greaterThanOrEqualTo(1));
    });

    test('a dead socket is a reachability failure', () async {
      backend.offline('/auth/me');

      await expectLater(
        client.get('/auth/me', bearer: 'token'),
        throwsA(isA<NetworkFailure>()),
      );

      expect(connectivity.successes, 0);
      expect(connectivity.failures, greaterThanOrEqualTo(1));
    });
  });

  group('the three-failure rule, driven by real calls', () {
    Future<void> failOnce() async {
      backend.offline('/auth/me');
      await expectLater(
        client.get('/auth/me', bearer: 'token'),
        throwsA(isA<NetworkFailure>()),
      );
    }

    test('one failed call is not enough', () async {
      await failOnce();

      expect(
        connectivity.current,
        Reachability.online,
        reason: 'a single dropped request on a moving bus is normal',
      );
    });

    test('two are still not enough', () async {
      await failOnce();
      await failOnce();

      expect(connectivity.current, Reachability.online);
    });

    test('three consecutive failures declare the app offline', () async {
      await failOnce();
      await failOnce();
      await failOnce();

      expect(connectivity.current, Reachability.offline);
    });

    test('a success in between resets the count', () async {
      await failOnce();
      await failOnce();

      backend.on('/auth/me', status: 200, body: meResponseBody());
      await client.get('/auth/me', bearer: 'token');

      expect(connectivity.current, Reachability.online);
      expect(
        connectivity.reports.last,
        isTrue,
        reason: 'failures must be consecutive, or a long shift with scattered '
            'dropouts eventually reads as permanently offline',
      );
    });

    test('a successful call after going offline brings it back', () async {
      await failOnce();
      await failOnce();
      await failOnce();
      expect(connectivity.current, Reachability.offline);

      backend.on('/auth/me', status: 200, body: meResponseBody());
      await client.get('/auth/me', bearer: 'token');

      expect(
        connectivity.current,
        Reachability.online,
        reason: 'offline is not a terminal state; the next answered call is '
            'the only evidence of recovery the app ever gets',
      );
    });
  });

  group('transport is not reachability', () {
    test('a client with no connectivity attached still works', () async {
      final unwired = ApiClient(
        baseUrl: 'http://localhost/api/v1',
        logger: SilentLogger(),
        retryDelays: const [],
      )..dio.httpClientAdapter = backend;

      backend.on('/auth/me', status: 200, body: meResponseBody());

      await expectLater(unwired.get('/auth/me', bearer: 'token'), completes);
    });

    test('the service refuses to call a returning radio proof of anything',
        () {
      // DefaultConnectivityService is the production implementation. Losing
      // the transport is proof of unreachable; getting it back is not proof
      // of the opposite, so it must not clear the state on its own.
      final service = DefaultConnectivityService.forTest();
      addTearDown(service.dispose);

      service
        ..recordFailure()
        ..recordFailure()
        ..recordFailure();
      expect(service.current, Reachability.offline);

      service.transportChanged(hasTransport: true);

      expect(
        service.current,
        Reachability.offline,
        reason: 'four bars behind a captive portal is not a route to the API; '
            'clearing the banner here tells a driver their boardings are '
            'being sent when they are not',
      );
    });

    test('losing the transport is proof enough on its own', () {
      final service = DefaultConnectivityService.forTest();
      addTearDown(service.dispose);

      expect(service.current, Reachability.online);

      service.transportChanged(hasTransport: false);

      expect(service.current, Reachability.offline);
    });
  });
}
