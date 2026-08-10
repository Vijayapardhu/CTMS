/// Which deployment this build talks to.
enum Flavor { development, staging, production }

/// Build-time configuration.
///
/// Read from `--dart-define` rather than a bundled file, so a release build
/// cannot ship pointing at staging and no secret ever enters the repository.
///
///     flutter run --dart-define=FLAVOR=development \
///                 --dart-define=CTMS_API_BASE_URL=http://10.0.2.2:8000/api/v1
///
///     flutter build apk --release \
///                 --dart-define=FLAVOR=production \
///                 --dart-define=CTMS_API_BASE_URL=https://<server>/api/v1
class AppConfig {
  const AppConfig({
    required this.flavor,
    required this.apiBaseUrl,
    required this.enableVerboseLogging,
  });

  final Flavor flavor;
  final String apiBaseUrl;
  final bool enableVerboseLogging;

  /// The emulator's route back to the developer's own machine. Fine for
  /// development, and a released build still carrying it reaches nothing at
  /// all on a real handset.
  static const developmentApiBaseUrl = 'http://10.0.2.2:8000/api/v1';

  bool get isProduction => flavor == Flavor.production;

  /// A production build that was never told where the server is.
  ///
  /// Cheaper to catch in the start-up log than in a demo, where it presents
  /// as every screen failing to load for no visible reason.
  bool get isMisconfigured => isProduction && apiBaseUrl == developmentApiBaseUrl;

  /// The host alone, for showing a tester which backend they are on without
  /// putting a full URL in a list tile.
  String get apiHost => Uri.tryParse(apiBaseUrl)?.host ?? apiBaseUrl;

  /// The developer-mode toggle is available off production only. A driver must
  /// not be able to reach diagnostics that could alter trip state.
  bool get allowsDeveloperMode => !isProduction;

  factory AppConfig.fromEnvironment() {
    const flavorName = String.fromEnvironment('FLAVOR', defaultValue: 'development');

    final flavor = switch (flavorName) {
      'production' => Flavor.production,
      // `demo` is the same build as staging under the name the people asking
      // for it use. One fewer thing to remember on the morning of a demo.
      'staging' || 'demo' => Flavor.staging,
      _ => Flavor.development,
    };

    return AppConfig(
      flavor: flavor,
      apiBaseUrl: const String.fromEnvironment(
        'CTMS_API_BASE_URL',
        defaultValue: developmentApiBaseUrl,
      ),
      enableVerboseLogging: flavor != Flavor.production,
    );
  }
}
