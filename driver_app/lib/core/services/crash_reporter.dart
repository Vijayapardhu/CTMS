/// Crash reporting boundary.
///
/// An abstraction rather than a direct Crashlytics dependency, because the
/// redaction rule matters more than the vendor: no personally identifying
/// information and no coordinates, ever. A concrete implementation is wired in
/// a later slice; [NoopCrashReporter] is the Slice 0 binding and is a complete
/// implementation, not a placeholder.
abstract interface class CrashReporter {
  Future<void> recordError(Object error, StackTrace? stackTrace,
      {bool fatal = false});

  /// Breadcrumbs. Never call with a value that identifies a person or place.
  Future<void> log(String message);

  /// The driver's opaque id only — never their name, email or telephone.
  Future<void> setUserIdentifier(String? id);
}

class NoopCrashReporter implements CrashReporter {
  const NoopCrashReporter();

  @override
  Future<void> recordError(Object error, StackTrace? stackTrace,
          {bool fatal = false}) async {}

  @override
  Future<void> log(String message) async {}

  @override
  Future<void> setUserIdentifier(String? id) async {}
}
