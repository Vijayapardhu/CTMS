import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  // The service subscribes to a platform event channel on construction, which
  // needs a binding even though these tests never emit through it.
  TestWidgetsFlutterBinding.ensureInitialized();

  group('DefaultConnectivityService', () {
    late DefaultConnectivityService service;

    setUp(() => service = DefaultConnectivityService(Connectivity()));
    tearDown(() => service.dispose());

    test('starts online rather than offline', () {
      expect(
        service.current,
        Reachability.online,
        reason: 'an app that assumes offline at launch queues the first '
            'action instead of sending it',
      );
    });

    test('one failure is not enough to declare the app offline', () {
      service.recordFailure();

      expect(
        service.current,
        Reachability.online,
        reason: 'a single dropped request on a moving bus is normal',
      );
    });

    test('two failures are still not enough', () {
      service
        ..recordFailure()
        ..recordFailure();

      expect(service.current, Reachability.online);
    });

    test('three consecutive failures declare the app offline', () {
      service
        ..recordFailure()
        ..recordFailure()
        ..recordFailure();

      expect(service.current, Reachability.offline);
    });

    test('a success resets the failure count', () {
      service
        ..recordFailure()
        ..recordFailure()
        ..recordSuccess()
        ..recordFailure()
        ..recordFailure();

      expect(
        service.current,
        Reachability.online,
        reason: 'failures must be consecutive; otherwise a long shift with '
            'scattered dropouts eventually reads as permanently offline',
      );
    });

    test('emits only on a change, never on every call', () async {
      final seen = <Reachability>[];
      service.changes.listen(seen.add);

      service
        ..recordFailure()
        ..recordFailure()
        ..recordFailure()
        ..recordFailure()
        ..recordFailure();

      await Future<void>.delayed(Duration.zero);

      expect(seen, [Reachability.offline]);
    });

    test('recovers to online on the next success', () async {
      final seen = <Reachability>[];
      service.changes.listen(seen.add);

      service
        ..recordFailure()
        ..recordFailure()
        ..recordFailure()
        ..recordSuccess();

      await Future<void>.delayed(Duration.zero);

      expect(seen, [Reachability.offline, Reachability.online]);
      expect(service.current, Reachability.online);
    });
  });
}
