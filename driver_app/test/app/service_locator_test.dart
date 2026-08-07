import 'package:ctms_driver/app/di/service_locator.dart';
import 'package:ctms_driver/app/lifecycle/app_lifecycle_observer.dart';
import 'package:ctms_driver/app/settings/app_preferences.dart';
import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/services/analytics_service.dart';
import 'package:ctms_driver/core/services/crash_reporter.dart';
import 'package:ctms_driver/core/services/logger_service.dart';
import 'package:ctms_driver/core/services/ui_service.dart';
import 'package:ctms_driver/core/storage/secure_store.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('service locator', () {
    setUp(() async => registerTestDependencies());
    tearDown(resetDependencies);

    test('registers every application-wide service', () {
      expect(sl.isRegistered<AppPreferences>(), isTrue);
      expect(sl.isRegistered<LoggerService>(), isTrue);
      expect(sl.isRegistered<CrashReporter>(), isTrue);
      expect(sl.isRegistered<AnalyticsService>(), isTrue);
      expect(sl.isRegistered<AppLifecycleObserver>(), isTrue);
      expect(sl.isRegistered<SecureStore>(), isTrue);
      expect(sl.isRegistered<ConnectivityService>(), isTrue);
      expect(sl.isRegistered<UiService>(), isTrue);
      expect(sl.isRegistered<ApiClient>(), isTrue);
    });

    test('resolving twice returns the same instance', () {
      expect(identical(sl<ApiClient>(), sl<ApiClient>()), isTrue);
      expect(identical(sl<AppPreferences>(), sl<AppPreferences>()), isTrue);
    });

    test('the API client points at the configured base URL', () {
      expect(sl<ApiClient>().dio.options.baseUrl, 'http://localhost/api/v1');
    });

    test('the API client refuses to throw on a non-2xx status', () {
      expect(
        sl<ApiClient>().dio.options.validateStatus(409),
        isTrue,
        reason: 'a 409 must arrive as a typed refusal, not as a transport '
            'exception the UI has to unpick',
      );
    });

    test('reset clears every registration', () async {
      await resetDependencies();

      expect(sl.isRegistered<ApiClient>(), isFalse);
    });
  });
}
