import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppStrings
/// returned by `AppStrings.of(context)`.
///
/// Applications need to include `AppStrings.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppStrings.localizationsDelegates,
///   supportedLocales: AppStrings.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppStrings.supportedLocales
/// property.
abstract class AppStrings {
  AppStrings(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppStrings of(BuildContext context) {
    return Localizations.of<AppStrings>(context, AppStrings)!;
  }

  static const LocalizationsDelegate<AppStrings> delegate =
      _AppStringsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[Locale('en')];

  /// Application name shown in the task switcher
  ///
  /// In en, this message translates to:
  /// **'CTMS Driver'**
  String get appTitle;

  /// Bottom navigation label for the trip tab
  ///
  /// In en, this message translates to:
  /// **'Trip'**
  String get tabTrip;

  /// Bottom navigation label for the map tab
  ///
  /// In en, this message translates to:
  /// **'Map'**
  String get tabMap;

  /// Bottom navigation label for the alerts tab
  ///
  /// In en, this message translates to:
  /// **'Alerts'**
  String get tabAlerts;

  /// Bottom navigation label for the profile tab
  ///
  /// In en, this message translates to:
  /// **'Me'**
  String get tabMe;

  /// Persistent banner shown while the API is unreachable
  ///
  /// In en, this message translates to:
  /// **'Offline — actions will be sent when you reconnect'**
  String get offlineBanner;

  /// Title of the error boundary screen
  ///
  /// In en, this message translates to:
  /// **'Something went wrong'**
  String get errorTitle;

  /// Body of the error boundary screen
  ///
  /// In en, this message translates to:
  /// **'The screen could not be displayed. Your trip is not affected.'**
  String get errorBody;

  /// Button that rebuilds the failed screen
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get errorRetry;

  /// Shown when a route does not resolve
  ///
  /// In en, this message translates to:
  /// **'Screen not found'**
  String get notFoundTitle;

  /// Body of the unknown-route screen
  ///
  /// In en, this message translates to:
  /// **'That link does not lead anywhere in this app.'**
  String get notFoundBody;

  /// Returns the driver to the default tab
  ///
  /// In en, this message translates to:
  /// **'Go to Trip'**
  String get goToTrip;

  /// Placeholder title on a screen with no feature slice yet
  ///
  /// In en, this message translates to:
  /// **'Not built yet'**
  String get comingSoon;

  /// Placeholder body on a screen with no feature slice yet
  ///
  /// In en, this message translates to:
  /// **'This screen is part of a later slice.'**
  String get comingSoonBody;

  /// Heading for the theme setting
  ///
  /// In en, this message translates to:
  /// **'Appearance'**
  String get settingsAppearance;

  /// Theme option that follows the device setting
  ///
  /// In en, this message translates to:
  /// **'Match device'**
  String get themeSystem;

  /// Light theme option
  ///
  /// In en, this message translates to:
  /// **'Light'**
  String get themeLight;

  /// Dark theme option
  ///
  /// In en, this message translates to:
  /// **'Dark'**
  String get themeDark;

  /// Label of the diagnostics toggle
  ///
  /// In en, this message translates to:
  /// **'Developer mode'**
  String get developerMode;

  /// Explains what the developer toggle does
  ///
  /// In en, this message translates to:
  /// **'Shows diagnostics. Unavailable in production builds.'**
  String get developerModeHint;

  /// Generic dismiss action
  ///
  /// In en, this message translates to:
  /// **'Cancel'**
  String get cancel;

  /// Generic retry action
  ///
  /// In en, this message translates to:
  /// **'Retry'**
  String get retry;

  /// Title of the login screen
  ///
  /// In en, this message translates to:
  /// **'Sign in'**
  String get loginTitle;

  /// Sub-heading under the login title
  ///
  /// In en, this message translates to:
  /// **'Use the account your transport office issued you.'**
  String get loginSubtitle;

  /// Label of the email field
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get loginEmail;

  /// Label of the password field
  ///
  /// In en, this message translates to:
  /// **'Password'**
  String get loginPassword;

  /// Reveals the typed password
  ///
  /// In en, this message translates to:
  /// **'Show password'**
  String get loginShowPassword;

  /// Conceals the typed password
  ///
  /// In en, this message translates to:
  /// **'Hide password'**
  String get loginHidePassword;

  /// Submits the login form
  ///
  /// In en, this message translates to:
  /// **'Sign in'**
  String get loginSubmit;

  /// Shown when the email field is empty
  ///
  /// In en, this message translates to:
  /// **'Enter your email'**
  String get loginEmailRequired;

  /// Shown when the email is malformed
  ///
  /// In en, this message translates to:
  /// **'That does not look like an email address'**
  String get loginEmailInvalid;

  /// Shown when the password field is empty
  ///
  /// In en, this message translates to:
  /// **'Enter your password'**
  String get loginPasswordRequired;

  /// Explains that sign-in cannot be queued
  ///
  /// In en, this message translates to:
  /// **'No connection. Sign-in needs a network.'**
  String get loginOffline;

  /// Title of the session-expired screen
  ///
  /// In en, this message translates to:
  /// **'Signed out'**
  String get expiredTitle;

  /// Shown when the refresh token was refused
  ///
  /// In en, this message translates to:
  /// **'Your session has ended. Sign in to continue.'**
  String get expiredRefreshRefused;

  /// Shown when the server refuses the account, which may mean deactivation
  ///
  /// In en, this message translates to:
  /// **'This account can no longer be used on this device. Contact your transport office if that is unexpected.'**
  String get expiredAccountUnavailable;

  /// Acknowledges the expiry and returns to login
  ///
  /// In en, this message translates to:
  /// **'Back to sign in'**
  String get expiredContinue;

  /// Signs out of this device
  ///
  /// In en, this message translates to:
  /// **'Sign out'**
  String get signOut;

  /// Title of the sign-out confirmation
  ///
  /// In en, this message translates to:
  /// **'Sign out?'**
  String get signOutConfirmTitle;

  /// Body of the sign-out confirmation
  ///
  /// In en, this message translates to:
  /// **'You will need your password to sign in again.'**
  String get signOutConfirmBody;

  /// Revokes every token the driver holds
  ///
  /// In en, this message translates to:
  /// **'Sign out of all devices'**
  String get signOutEverywhere;

  /// Title of the sign-out-everywhere confirmation
  ///
  /// In en, this message translates to:
  /// **'Sign out everywhere?'**
  String get signOutEverywhereConfirmTitle;

  /// Body of the sign-out-everywhere confirmation
  ///
  /// In en, this message translates to:
  /// **'Every device signed in as you will be signed out, including this one.'**
  String get signOutEverywhereConfirmBody;

  /// Shown when logout-all did not reach the server
  ///
  /// In en, this message translates to:
  /// **'Could not sign out your other devices. You are still signed in here.'**
  String get signOutEverywhereFailed;

  /// Heading for the account section of the Me screen
  ///
  /// In en, this message translates to:
  /// **'Account'**
  String get settingsAccount;

  /// Shows the driver's licence number
  ///
  /// In en, this message translates to:
  /// **'Licence {number}'**
  String accountLicence(String number);

  /// Shown while the session is running from cache
  ///
  /// In en, this message translates to:
  /// **'Signed in from saved credentials — not yet confirmed with the server'**
  String get accountUnconfirmed;

  /// R1 empty state — the server answered and there is no trip
  ///
  /// In en, this message translates to:
  /// **'No trip assigned today'**
  String get tripNoneTitle;

  /// Supporting line under the no-trip empty state
  ///
  /// In en, this message translates to:
  /// **'If you expect one, contact the transport office.'**
  String get tripNoneBody;

  /// R1 unavailable — the read failed and nothing is cached
  ///
  /// In en, this message translates to:
  /// **'Could not load today\'s trip'**
  String get tripUnavailableTitle;

  /// Explains that unavailable is not the same as none
  ///
  /// In en, this message translates to:
  /// **'This is not the same as having no trip. Check your connection and try again.'**
  String get tripUnavailableBody;

  /// Retry action on the trip error card
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get tripRetry;

  /// Marks a trip retained after a failed refresh
  ///
  /// In en, this message translates to:
  /// **'Showing the last known trip — this could not be refreshed'**
  String get tripStale;

  /// Trip card status chip when the bus is cleared
  ///
  /// In en, this message translates to:
  /// **'READY'**
  String get tripStatusReady;

  /// Trip card status chip when the bus is not cleared
  ///
  /// In en, this message translates to:
  /// **'NOT READY'**
  String get tripStatusBlocked;

  /// Trip card status chip while the trip is in progress
  ///
  /// In en, this message translates to:
  /// **'RUNNING'**
  String get tripStatusRunning;

  /// Trip card status chip outside the start window
  ///
  /// In en, this message translates to:
  /// **'WAITING'**
  String get tripStatusWaiting;

  /// Trip card status chip for a finished trip
  ///
  /// In en, this message translates to:
  /// **'COMPLETED'**
  String get tripStatusCompleted;

  /// Trip card status chip for a cancelled trip
  ///
  /// In en, this message translates to:
  /// **'CANCELLED'**
  String get tripStatusCancelled;

  /// Heading for reasons the driver can act on
  ///
  /// In en, this message translates to:
  /// **'You can fix this'**
  String get tripReasonsActionable;

  /// Heading for reasons only operations can fix
  ///
  /// In en, this message translates to:
  /// **'Operations must fix this'**
  String get tripReasonsBlocking;

  /// Scheduled departure time
  ///
  /// In en, this message translates to:
  /// **'Departs {time}'**
  String tripDeparture(String time);

  /// Number of stops on the route
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 stop} other{{count} stops}}'**
  String tripStopCount(int count);

  /// Booked seat count for the trip
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 student expected} other{{count} students expected}}'**
  String tripExpected(int count);

  /// Occupancy while a trip is running
  ///
  /// In en, this message translates to:
  /// **'{occupied} of {capacity} on board'**
  String tripOnBoard(int occupied, int capacity);

  /// The server's cancellation reason, shown verbatim
  ///
  /// In en, this message translates to:
  /// **'Cancelled: {reason}'**
  String tripCancelledBecause(String reason);

  /// Shown when the server closed the trip without the driver
  ///
  /// In en, this message translates to:
  /// **'This trip was closed automatically.'**
  String get tripAutoClosed;

  /// When the readiness answer was obtained
  ///
  /// In en, this message translates to:
  /// **'Clearance checked at {time}'**
  String tripReadinessCheckedAt(String time);

  /// Countdown target while waiting for the start window
  ///
  /// In en, this message translates to:
  /// **'Start opens at {time}'**
  String tripStartWindowOpens(String time);
}

class _AppStringsDelegate extends LocalizationsDelegate<AppStrings> {
  const _AppStringsDelegate();

  @override
  Future<AppStrings> load(Locale locale) {
    return SynchronousFuture<AppStrings>(lookupAppStrings(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppStringsDelegate old) => false;
}

AppStrings lookupAppStrings(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'en':
      return AppStringsEn();
  }

  throw FlutterError(
    'AppStrings.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
