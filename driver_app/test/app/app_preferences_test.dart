import 'package:ctms_driver/app/config/app_config.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_harness.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('AppPreferences', () {
    test('defaults to the device theme', () async {
      await registerTestDependencies();

      expect(testPreferences.themeMode, ThemeMode.system);
    });

    test('restores a stored theme', () async {
      await registerTestDependencies(preferences: {'theme_mode': 'dark'});

      expect(testPreferences.themeMode, ThemeMode.dark);
    });

    test('an unrecognised stored value falls back to the device theme',
        () async {
      await registerTestDependencies(preferences: {'theme_mode': 'sepia'});

      expect(testPreferences.themeMode, ThemeMode.system);
    });

    test('a theme change notifies listeners exactly once', () async {
      await registerTestDependencies();

      var notifications = 0;
      testPreferences.addListener(() => notifications++);

      await testPreferences.setThemeMode(ThemeMode.dark);
      await testPreferences.setThemeMode(ThemeMode.dark);

      expect(
        notifications,
        1,
        reason: 'setting the same value again must not rebuild the whole app',
      );
    });

    test('developer mode is refused in a production build', () async {
      await registerTestDependencies(
        flavor: Flavor.production,
        preferences: {'developer_mode': true},
      );

      expect(
        testPreferences.developerMode,
        isFalse,
        reason: 'a stored true from a prior staging install must not unlock '
            'diagnostics after a production upgrade',
      );
      expect(testPreferences.canToggleDeveloperMode, isFalse);
    });

    test('setting developer mode in production is a no-op', () async {
      await registerTestDependencies(flavor: Flavor.production);

      await testPreferences.setDeveloperMode(true);

      expect(testPreferences.developerMode, isFalse);
    });

    test('developer mode persists in a development build', () async {
      await registerTestDependencies();

      await testPreferences.setDeveloperMode(true);

      expect(testPreferences.developerMode, isTrue);
      expect(testPreferences.canToggleDeveloperMode, isTrue);
    });
  });
}
