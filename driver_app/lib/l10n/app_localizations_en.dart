// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppStringsEn extends AppStrings {
  AppStringsEn([String locale = 'en']) : super(locale);

  @override
  String get appTitle => 'CTMS Driver';

  @override
  String get tabTrip => 'Trip';

  @override
  String get tabMap => 'Map';

  @override
  String get tabAlerts => 'Alerts';

  @override
  String get tabMe => 'Me';

  @override
  String get offlineBanner =>
      'Offline — actions will be sent when you reconnect';

  @override
  String get errorTitle => 'Something went wrong';

  @override
  String get errorBody =>
      'The screen could not be displayed. Your trip is not affected.';

  @override
  String get errorRetry => 'Try again';

  @override
  String get notFoundTitle => 'Screen not found';

  @override
  String get notFoundBody => 'That link does not lead anywhere in this app.';

  @override
  String get goToTrip => 'Go to Trip';

  @override
  String get comingSoon => 'Not built yet';

  @override
  String get comingSoonBody => 'This screen is part of a later slice.';

  @override
  String get settingsAppearance => 'Appearance';

  @override
  String get themeSystem => 'Match device';

  @override
  String get themeLight => 'Light';

  @override
  String get themeDark => 'Dark';

  @override
  String get developerMode => 'Developer mode';

  @override
  String get developerModeHint =>
      'Shows diagnostics. Unavailable in production builds.';

  @override
  String get cancel => 'Cancel';

  @override
  String get retry => 'Retry';
}
