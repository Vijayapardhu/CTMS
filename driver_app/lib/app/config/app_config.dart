/// Which deployment this build talks to.
enum Flavor { development, staging, production }

/// Build-time configuration.
///
/// Read from `--dart-define` rather than a bundled file, so a release build
/// cannot ship pointing at staging and no secret ever enters the repository.
///
///     flutter run --dart-define=FLAVOR=development \
///                 --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
class AppConfig {
  const AppConfig({
    required this.flavor,
    required this.apiBaseUrl,
    required this.enableVerboseLogging,
  });

  final Flavor flavor;
  final String apiBaseUrl;
  final bool enableVerboseLogging;

  bool get isProduction => flavor == Flavor.production;

  /// The developer-mode toggle is available off production only. A driver must
  /// not be able to reach diagnostics that could alter trip state.
  bool get allowsDeveloperMode => !isProduction;

  factory AppConfig.fromEnvironment() {
    const flavorName = String.fromEnvironment('FLAVOR', defaultValue: 'development');

    final flavor = switch (flavorName) {
      'production' => Flavor.production,
      'staging' => Flavor.staging,
      _ => Flavor.development,
    };

    return AppConfig(
      flavor: flavor,
      // 10.0.2.2 is the host machine from an Android emulator.
      apiBaseUrl: const String.fromEnvironment(
        'API_BASE_URL',
        defaultValue: 'http://10.0.2.2:8000/api/v1',
      ),
      enableVerboseLogging: flavor != Flavor.production,
    );
  }
}
