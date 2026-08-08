import 'dart:async';
import 'dart:convert';

import 'package:ctms_driver/app/app.dart';
import 'package:ctms_driver/app/config/app_config.dart';
import 'package:ctms_driver/app/di/service_locator.dart';
import 'package:ctms_driver/app/settings/app_preferences.dart';
import 'package:ctms_driver/app/theme/app_theme.dart';
import 'package:ctms_driver/core/api/api_client.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:ctms_driver/core/storage/secure_store.dart';
import 'package:ctms_driver/features/auth/domain/session_state.dart';
import 'package:ctms_driver/features/auth/presentation/bloc/session_bloc.dart';
import 'package:ctms_driver/l10n/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'auth_fixtures.dart';
import 'fake_backend.dart';
import 'test_doubles.dart';

/// A [ConnectivityService] the test drives directly.
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

/// Everything a widget test needs to reach into the booted app.
class TestApp {
  const TestApp({
    required this.connectivity,
    required this.backend,
    required this.store,
  });

  final FakeConnectivity connectivity;
  final FakeBackend backend;
  final InMemorySecureStore store;

  SessionBloc get session => sl<SessionBloc>();

  Future<void> dispose() async {
    await connectivity.dispose();
    await resetDependencies();
  }
}

/// Boots the real dependency graph.
///
/// Every object under test is the production one. Only the three things backed
/// by a platform channel or a socket are substituted: preferences, secure
/// storage and connectivity — plus the HTTP adapter, so requests stop at
/// [FakeBackend] instead of the network.
Future<TestApp> registerTestDependencies({
  Flavor flavor = Flavor.development,
  Map<String, Object> preferences = const {},
  bool signedIn = false,
}) async {
  await resetDependencies();

  SharedPreferences.setMockInitialValues(preferences);

  final backend = FakeBackend();
  final store = InMemorySecureStore();
  final connectivity = FakeConnectivity();

  if (signedIn) {
    // Written straight to the store, so `SessionStarted` takes the real
    // restore path rather than a shortcut the production app never uses.
    store.values[SecureKeys.tokens] = jsonEncode({
      'access_token': 'access-1',
      'refresh_token': 'refresh-1',
      'access_expires_at':
          DateTime.now().toUtc().add(const Duration(hours: 1)).toIso8601String(),
    });
    store.values[SecureKeys.user] = jsonEncode(userJson());

    backend.on('/auth/me', status: 200, body: meResponseBody());
  }

  await configureDependencies(
    AppConfig(
      flavor: flavor,
      apiBaseUrl: 'http://localhost/api/v1',
      enableVerboseLogging: false,
    ),
    secureStore: store,
    connectivity: connectivity,
  );

  sl<ApiClient>().dio.httpClientAdapter = backend;
  sl<ApiClient>(instanceName: authClientName).dio.httpClientAdapter = backend;

  return TestApp(connectivity: connectivity, backend: backend, store: store);
}

/// Boots the app and settles it into its first real screen.
///
/// The splash carries a spinner, so `pumpAndSettle` would never return while
/// it is on screen — this waits for the session to resolve and only then
/// settles.
Future<void> pumpApp(WidgetTester tester) async {
  await tester.pumpWidget(const CtmsDriverApp());
  await waitForSession(tester, (s) => s is! SessionInitialising);
  await settle(tester);
}

/// Drives the app until the session satisfies [matches].
///
/// Polls rather than awaiting `bloc.stream`. Two things defeat a `firstWhere`
/// here:
///
/// * The bloc's stream does not replay. Session work is fast enough to finish
///   during the `await` of the action that started it, so a listener attached
///   afterwards waits forever for an event that already happened.
/// * The work mixes real asynchrony (storage, an HTTP round trip) with timers
///   scheduled in the test's fake-async zone — the client's retry back-off.
///   Only `runAsync` advances the first and only `pump` advances the second,
///   so each turn of this loop does both.
Future<void> waitForSession(
  WidgetTester tester,
  bool Function(SessionState) matches, {
  int turns = 120,
}) async {
  final bloc = sl<SessionBloc>();

  for (var turn = 0; turn < turns; turn++) {
    if (matches(bloc.state)) {
      await tester.pump();
      return;
    }

    await tester.runAsync(() => Future<void>.delayed(_realTurn));
    await tester.pump(_fakeTurn);
  }

  fail(
    'The session never reached the expected state. It is ${bloc.state}.',
  );
}

/// Long enough for the real event loop to deliver an HTTP response.
const _realTurn = Duration(milliseconds: 5);

/// Advances the fake clock. 120 turns covers the client's 1s + 3s retry
/// back-off with room to spare.
const _fakeTurn = Duration(milliseconds: 100);

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

/// Settles the tree, giving up after ten seconds.
///
/// The default timeout is ten *minutes*. A screen that never stops animating —
/// a spinner the test did not expect to still be there — would otherwise stall
/// the suite for ten minutes per occurrence instead of failing with a name and
/// a stack.
Future<void> settle(WidgetTester tester) => tester.pumpAndSettle(
      const Duration(milliseconds: 100),
      EnginePhase.sendSemanticsUpdate,
      const Duration(seconds: 10),
    );
