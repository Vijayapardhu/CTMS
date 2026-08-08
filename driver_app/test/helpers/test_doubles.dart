import 'package:ctms_driver/core/services/analytics_service.dart';
import 'package:ctms_driver/core/services/crash_reporter.dart';
import 'package:ctms_driver/core/services/logger_service.dart';
import 'package:ctms_driver/core/storage/secure_store.dart';

/// A logger that swallows everything. Tests assert on behaviour, not on
/// console noise, and a real logger makes a failing suite unreadable.
class SilentLogger implements LoggerService {
  @override
  void debug(String message, {Map<String, Object?>? context}) {}

  @override
  void info(String message, {Map<String, Object?>? context}) {}

  @override
  void warn(String message, {Map<String, Object?>? context}) {}

  @override
  void error(String message, {Object? error, StackTrace? stackTrace}) {}
}

/// The secure store, without the keystore.
///
/// The real one needs a platform channel; this one has identical semantics,
/// so a test can still prove that logout cleared both keys.
class InMemorySecureStore implements SecureStore {
  final Map<String, String> values = {};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);

  @override
  Future<void> clear() async => values.clear();
}

/// Records what would have gone to the crash reporter, so the tests can prove
/// the identifier is set on sign-in and cleared on sign-out.
class RecordingCrashReporter implements CrashReporter {
  final List<String?> identifiers = [];
  final List<Object> errors = [];

  @override
  Future<void> recordError(Object error, StackTrace? stackTrace,
      {bool fatal = false}) async {
    errors.add(error);
  }

  @override
  Future<void> log(String message) async {}

  @override
  Future<void> setUserIdentifier(String? id) async => identifiers.add(id);
}

/// Records analytics events.
class RecordingAnalytics implements AnalyticsService {
  final List<String> events = [];
  final List<String> screens = [];

  @override
  Future<void> screenView(String name) async => screens.add(name);

  @override
  Future<void> track(String event,
          {Map<String, Object?> properties = const {}}) async =>
      events.add(event);
}
