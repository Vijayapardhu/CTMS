import 'package:ctms_driver/core/services/analytics_service.dart';
import 'package:ctms_driver/core/services/crash_reporter.dart';
import 'package:ctms_driver/core/services/permission_service.dart';
import 'package:ctms_driver/features/evidence/data/photo_capture.dart';
import 'package:ctms_driver/features/evidence/domain/evidence.dart';
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

/// The smallest valid PNG, so a thumbnail can actually be decoded.
const onePixelPng = <int>[137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0, 0, 1, 0, 0, 0, 1, 8, 6, 0, 0, 0, 31, 21, 196, 137, 0, 0, 0, 13, 73, 68, 65, 84, 120, 218, 99, 252, 207, 192, 80, 15, 0, 4, 133, 1, 128, 132, 169, 140, 33, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130];

/// A camera the test drives.
///
/// The real one is a platform channel and an OEM camera app; what matters to
/// every rule above it is only what comes back.
class FakeCamera implements PhotoCapture {
  FakeCamera({this.photo, this.cancelled = false});

  /// What the shutter produces. Defaults to a small, plausible JPEG.
  CapturedPhoto? photo;

  /// The driver backed out of the camera.
  bool cancelled;

  int takes = 0;

  @override
  Future<CapturedPhoto?> take() async {
    takes++;
    if (cancelled) return null;

    return photo ??
        const CapturedPhoto(
          // A real, decodable image: the card renders a thumbnail, and filler
          // bytes would fail the decoder rather than the rule under test.
          bytes: onePixelPng,
          mimeType: 'image/png',
          fileName: 'evidence-test.png',
        );
  }
}

/// Permissions the test decides.
class FakePermissions implements PermissionService {
  FakePermissions([this.answer = PermissionStatus.granted]);

  PermissionStatus answer;
  int settingsOpened = 0;
  final List<AppPermission> requested = [];

  @override
  Future<PermissionStatus> status(AppPermission permission) async => answer;

  @override
  Future<PermissionStatus> request(AppPermission permission) async {
    requested.add(permission);
    return answer;
  }

  @override
  Future<void> openSettings() async => settingsOpened++;
}
