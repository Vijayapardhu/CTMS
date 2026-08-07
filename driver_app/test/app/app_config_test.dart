import 'package:ctms_driver/app/config/app_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('AppConfig', () {
    test('defaults to development when nothing is defined', () {
      final config = AppConfig.fromEnvironment();

      expect(config.flavor, Flavor.development);
      expect(config.apiBaseUrl, isNotEmpty);
    });

    test('production disables verbose logging', () {
      const config = AppConfig(
        flavor: Flavor.production,
        apiBaseUrl: 'https://ctms.example/api/v1',
        enableVerboseLogging: false,
      );

      expect(config.isProduction, isTrue);
      expect(config.enableVerboseLogging, isFalse);
    });

    test('developer mode is unavailable in production', () {
      const config = AppConfig(
        flavor: Flavor.production,
        apiBaseUrl: 'https://ctms.example/api/v1',
        enableVerboseLogging: false,
      );

      expect(
        config.allowsDeveloperMode,
        isFalse,
        reason: 'diagnostics that can alter trip state must not be reachable '
            'from a driver handset',
      );
    });

    test('developer mode is available in staging', () {
      const config = AppConfig(
        flavor: Flavor.staging,
        apiBaseUrl: 'https://staging.ctms.example/api/v1',
        enableVerboseLogging: true,
      );

      expect(config.allowsDeveloperMode, isTrue);
      expect(config.isProduction, isFalse);
    });
  });
}
