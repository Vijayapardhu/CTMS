import 'dart:async';

import 'package:ctms_driver/app/config/app_config.dart';
import 'package:ctms_driver/app/di/service_locator.dart';
import 'package:ctms_driver/app/settings/app_preferences.dart';
import 'package:ctms_driver/app/theme/app_theme.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/l10n/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// A [ConnectivityService] whose state the test drives directly.
///
/// The real one listens to a platform channel that does not exist in a test
/// binding, and a test that cannot make the app go offline cannot check the
/// only behaviour the banner has.
class FakeConnectivity implements ConnectivityService {
  Reachability _current = Reachability.online;
  final _controller = StreamController<Reachability>.broadcast();

  int failures = 0;
  int successes = 0;

  @override
  Stream<Reachability> get changes => _controller.stream;

  @override
  Reachability get current => _current;

  @override
  void recordFailure() => failures++;

  @override
  void recordSuccess() => successes++;

  void emit(Reachability value) {
    _current = value;
    _controller.add(value);
  }

  Future<void> dispose() => _controller.close();
}

/// Boots the real dependency graph against in-memory storage.
///
/// Everything the tests touch is the production object; only the two things
/// backed by a platform channel — preferences and connectivity — are replaced.
Future<FakeConnectivity> registerTestDependencies({
  Flavor flavor = Flavor.development,
  Map<String, Object> preferences = const {},
}) async {
  await resetDependencies();

  SharedPreferences.setMockInitialValues(preferences);

  final config = AppConfig(
    flavor: flavor,
    apiBaseUrl: 'http://localhost/api/v1',
    enableVerboseLogging: false,
  );

  await configureDependencies(config);

  final connectivity = FakeConnectivity();
  sl
    ..unregister<ConnectivityService>()
    ..registerSingleton<ConnectivityService>(connectivity);

  return connectivity;
}

/// Wraps [child] in the minimum the widgets under test expect: the theme, the
/// localizations, and a navigator.
Widget wrapForTest(Widget child, {ThemeMode themeMode = ThemeMode.light}) {
  return MaterialApp(
    theme: AppTheme.light,
    darkTheme: AppTheme.dark,
    themeMode: themeMode,
    localizationsDelegates: AppStrings.localizationsDelegates,
    supportedLocales: AppStrings.supportedLocales,
    home: child,
  );
}

/// Reads the preferences singleton. A shorthand used by several tests.
AppPreferences get testPreferences => sl<AppPreferences>();

/// Settles the tree and fails loudly if an animation never finishes, rather
/// than timing out with no explanation.
Future<void> settle(WidgetTester tester) =>
    tester.pumpAndSettle(const Duration(milliseconds: 100));
