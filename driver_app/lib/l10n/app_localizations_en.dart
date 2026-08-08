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

  @override
  String get loginTitle => 'Sign in';

  @override
  String get loginSubtitle =>
      'Use the account your transport office issued you.';

  @override
  String get loginEmail => 'Email';

  @override
  String get loginPassword => 'Password';

  @override
  String get loginShowPassword => 'Show password';

  @override
  String get loginHidePassword => 'Hide password';

  @override
  String get loginSubmit => 'Sign in';

  @override
  String get loginEmailRequired => 'Enter your email';

  @override
  String get loginEmailInvalid => 'That does not look like an email address';

  @override
  String get loginPasswordRequired => 'Enter your password';

  @override
  String get loginOffline => 'No connection. Sign-in needs a network.';

  @override
  String get expiredTitle => 'Signed out';

  @override
  String get expiredRefreshRefused =>
      'Your session has ended. Sign in to continue.';

  @override
  String get expiredAccountUnavailable =>
      'This account can no longer be used on this device. Contact your transport office if that is unexpected.';

  @override
  String get expiredContinue => 'Back to sign in';

  @override
  String get signOut => 'Sign out';

  @override
  String get signOutConfirmTitle => 'Sign out?';

  @override
  String get signOutConfirmBody =>
      'You will need your password to sign in again.';

  @override
  String get signOutEverywhere => 'Sign out of all devices';

  @override
  String get signOutEverywhereConfirmTitle => 'Sign out everywhere?';

  @override
  String get signOutEverywhereConfirmBody =>
      'Every device signed in as you will be signed out, including this one.';

  @override
  String get signOutEverywhereFailed =>
      'Could not sign out your other devices. You are still signed in here.';

  @override
  String get settingsAccount => 'Account';

  @override
  String accountLicence(String number) {
    return 'Licence $number';
  }

  @override
  String get accountUnconfirmed =>
      'Signed in from saved credentials — not yet confirmed with the server';
}
