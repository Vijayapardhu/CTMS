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
