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

  /// R4 section header for build identity
  ///
  /// In en, this message translates to:
  /// **'About'**
  String get settingsAbout;

  /// R4 — the build a driver is running, for support calls
  ///
  /// In en, this message translates to:
  /// **'Version {version} ({build})'**
  String aboutVersion(String version, String build);

  /// R4 — shown off production only, so a tester knows which backend they are on
  ///
  /// In en, this message translates to:
  /// **'{flavor} build · {host}'**
  String aboutEnvironment(String flavor, String host);

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

  /// R1 waiting — reassures that the early-start refusal resolves on its own
  ///
  /// In en, this message translates to:
  /// **'Start will unlock by itself. You do not need to do anything.'**
  String get tripStartWindowWaiting;

  /// R2 when there is nothing to track
  ///
  /// In en, this message translates to:
  /// **'No trip is running'**
  String get mapIdleTitle;

  /// R2 idle body
  ///
  /// In en, this message translates to:
  /// **'The map shows the bus once a trip has started.'**
  String get mapIdleBody;

  /// Label on the bus marker
  ///
  /// In en, this message translates to:
  /// **'Your bus'**
  String get mapBusMarker;

  /// R2 recentre action
  ///
  /// In en, this message translates to:
  /// **'Centre on the bus'**
  String get mapRecentre;

  /// R2 stale badge — an old position is never shown as current
  ///
  /// In en, this message translates to:
  /// **'{minutes, plural, =1{Position 1 minute old} other{Position {minutes} minutes old}}'**
  String mapPositionAge(int minutes);

  /// R2 — the server flagged the position stale with no age
  ///
  /// In en, this message translates to:
  /// **'Location may be outdated'**
  String get mapPositionStale;

  /// R2 — the live poll is failing; what is drawn is older than it looks
  ///
  /// In en, this message translates to:
  /// **'Not updating — showing the last known position'**
  String get mapPollFailed;

  /// R2 — the Maps SDK did not load. Distinct from having no bus position
  ///
  /// In en, this message translates to:
  /// **'Map unavailable'**
  String get mapUnavailableTitle;

  /// R2 map failure body
  ///
  /// In en, this message translates to:
  /// **'The map could not load. Tracking is unaffected — your position is still being sent.'**
  String get mapUnavailableBody;

  /// R2 sheet heading
  ///
  /// In en, this message translates to:
  /// **'NEXT STOP'**
  String get mapNextStop;

  /// Fallback when the server sent no stop name
  ///
  /// In en, this message translates to:
  /// **'Next stop'**
  String get mapUnnamedStop;

  /// R2 — the bus is standing at a stop
  ///
  /// In en, this message translates to:
  /// **'At {stop}'**
  String mapAtStop(String stop);

  /// R2 — every stop is done
  ///
  /// In en, this message translates to:
  /// **'No stops remaining'**
  String get mapNoMoreStops;

  /// R2 — a live estimate from the backend Route Matrix, as h:mm:ss
  ///
  /// In en, this message translates to:
  /// **'{clock}'**
  String mapEtaMinutes(String clock);

  /// R2 — the server computed this from a position it no longer trusts
  ///
  /// In en, this message translates to:
  /// **'About {clock} — not updating'**
  String mapEtaStale(String clock);

  /// R2 — no live position, so the estimate is the schedule
  ///
  /// In en, this message translates to:
  /// **'{clock} by timetable'**
  String mapEtaScheduled(String clock);

  /// R2 — the estimate has run down to zero
  ///
  /// In en, this message translates to:
  /// **'Arriving now'**
  String get mapEtaArrivingNow;

  /// R2 — scheduled basis with no minutes
  ///
  /// In en, this message translates to:
  /// **'Running to timetable — no live estimate yet'**
  String get mapEtaScheduledOnly;

  /// R2 — the bus reached this stop
  ///
  /// In en, this message translates to:
  /// **'Arrived'**
  String get mapEtaArrived;

  /// R2 — the ETA call failed or the server could not say
  ///
  /// In en, this message translates to:
  /// **'No estimate available'**
  String get mapEtaUnavailable;

  /// R2 — how many stops before the next one
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 stop away} other{{count} stops away}}'**
  String mapStopsAway(int count);

  /// R2 — stops failed to load; distinct from the map failing
  ///
  /// In en, this message translates to:
  /// **'Route could not be loaded — the bus position is still live.'**
  String get mapRouteUnavailable;

  /// R3 empty state — calm, not an error
  ///
  /// In en, this message translates to:
  /// **'Nothing from the office'**
  String get alertsEmptyTitle;

  /// R3 empty body
  ///
  /// In en, this message translates to:
  /// **'Alerts from the transport office appear here.'**
  String get alertsEmptyBody;

  /// Marks an unread alert
  ///
  /// In en, this message translates to:
  /// **'NEW'**
  String get alertsNew;

  /// R3 action, shown only when something is unread
  ///
  /// In en, this message translates to:
  /// **'Mark all read'**
  String get alertsMarkAllRead;

  /// R3 — the refresh failed; the list held is not current
  ///
  /// In en, this message translates to:
  /// **'Showing the last alerts received — this could not be refreshed'**
  String get alertsStale;

  /// P17 title
  ///
  /// In en, this message translates to:
  /// **'Emergency'**
  String get sosTitle;

  /// P17 instruction above the control
  ///
  /// In en, this message translates to:
  /// **'Hold the button to alert the transport office.'**
  String get sosPrompt;

  /// Label on the hold-to-activate control
  ///
  /// In en, this message translates to:
  /// **'SOS'**
  String get sosHold;

  /// Shown while the hold is in progress
  ///
  /// In en, this message translates to:
  /// **'Keep holding'**
  String get sosHolding;

  /// P17 — reassures that no form follows
  ///
  /// In en, this message translates to:
  /// **'You do not need to type anything. Your bus and position are sent automatically.'**
  String get sosNoDetailsNeeded;

  /// P17 — the server accepted the alert
  ///
  /// In en, this message translates to:
  /// **'Help has been alerted'**
  String get sosSentTitle;

  /// Fallback when the server sent no message
  ///
  /// In en, this message translates to:
  /// **'The transport office has your alert.'**
  String get sosSentBody;

  /// P17 — no signal; the alert is held on the phone
  ///
  /// In en, this message translates to:
  /// **'Saved — not yet sent'**
  String get sosQueuedTitle;

  /// P17 queued body — never claims the alert was sent
  ///
  /// In en, this message translates to:
  /// **'Your phone is holding this alert and will send it as soon as there is signal. Call the office now if you can.'**
  String get sosQueuedBody;

  /// Shown instead of call/SMS when no number is set
  ///
  /// In en, this message translates to:
  /// **'No emergency number is configured on this device.'**
  String get sosNoNumber;

  /// Native dialler fallback
  ///
  /// In en, this message translates to:
  /// **'Call the office'**
  String get sosCall;

  /// Native SMS fallback, pre-filled with coordinates
  ///
  /// In en, this message translates to:
  /// **'Send a text with my position'**
  String get sosSms;

  /// Entry point on the running trip
  ///
  /// In en, this message translates to:
  /// **'SOS'**
  String get sosOpen;

  /// P18 title
  ///
  /// In en, this message translates to:
  /// **'Report a problem'**
  String get incidentTitle;

  /// Entry point on the running trip
  ///
  /// In en, this message translates to:
  /// **'Report a problem'**
  String get incidentOpen;

  /// P18 type picker heading
  ///
  /// In en, this message translates to:
  /// **'What has happened?'**
  String get incidentWhatHappened;

  /// Marks a type the server will refuse without evidence
  ///
  /// In en, this message translates to:
  /// **'A photograph is required'**
  String get incidentNeedsPhoto;

  /// P18 description field
  ///
  /// In en, this message translates to:
  /// **'What happened?'**
  String get incidentDescription;

  /// Keeps the driver from writing an essay
  ///
  /// In en, this message translates to:
  /// **'A sentence is enough.'**
  String get incidentDescriptionHint;

  /// Names what the photograph is for
  ///
  /// In en, this message translates to:
  /// **'A photograph is required for {label}'**
  String incidentEvidenceRequired(String label);

  /// vehicle_can_continue
  ///
  /// In en, this message translates to:
  /// **'The bus can keep going'**
  String get incidentCanContinue;

  /// Explains the consequence of the switch
  ///
  /// In en, this message translates to:
  /// **'Turn this off if the bus cannot be driven.'**
  String get incidentCanContinueHint;

  /// P18 submit action
  ///
  /// In en, this message translates to:
  /// **'Report it'**
  String get incidentSubmit;

  /// Fallback when the server sent no message
  ///
  /// In en, this message translates to:
  /// **'Reported'**
  String get incidentReported;

  /// Shown when the server grounded the vehicle
  ///
  /// In en, this message translates to:
  /// **'Bus out of service'**
  String get incidentBusOutOfService;

  /// The consequence, in the server's terms
  ///
  /// In en, this message translates to:
  /// **'A maintenance ticket has been opened. Do not continue this trip.'**
  String get incidentMaintenanceOpened;

  /// Leaves the incident screen
  ///
  /// In en, this message translates to:
  /// **'Back to trip'**
  String get incidentBackToTrip;

  /// P18 — held on the phone
  ///
  /// In en, this message translates to:
  /// **'Saved — not yet sent'**
  String get incidentQueuedTitle;

  /// P18 queued body
  ///
  /// In en, this message translates to:
  /// **'This report will be sent when you have signal.'**
  String get incidentQueuedBody;

  /// P18 — the types read failed
  ///
  /// In en, this message translates to:
  /// **'Cannot load the problem list'**
  String get incidentTypesUnavailableTitle;

  /// P18 types failure body
  ///
  /// In en, this message translates to:
  /// **'The list of problem types comes from the office and could not be read. Your trip is not affected.'**
  String get incidentTypesUnavailableBody;

  /// Label above the occupancy figure
  ///
  /// In en, this message translates to:
  /// **'ON BOARD'**
  String get opsOnBoard;

  /// Counter button — one more passenger aboard
  ///
  /// In en, this message translates to:
  /// **'ON'**
  String get opsBoard;

  /// Counter button — one passenger off
  ///
  /// In en, this message translates to:
  /// **'OFF'**
  String get opsAlight;

  /// Taps the server has not acknowledged. Not an error
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 not yet sent} other{{count} not yet sent}}'**
  String opsNotYetSynced(int count);

  /// Arrival control once the bus is inside the stop's radius
  ///
  /// In en, this message translates to:
  /// **'I have arrived at {stop}'**
  String opsArrivedAt(String stop);

  /// Shown when the device's own fix puts the bus inside the stop radius
  ///
  /// In en, this message translates to:
  /// **'You are at {stop}'**
  String opsAtStopNow(String stop);

  /// How far the bus still is from the next stop
  ///
  /// In en, this message translates to:
  /// **'{distance} to {stop}'**
  String opsDistanceToStop(String distance, String stop);

  /// Marks the bus as standing at the next stop
  ///
  /// In en, this message translates to:
  /// **'Arrived'**
  String get opsArrived;

  /// Opens the skip-stop sheet
  ///
  /// In en, this message translates to:
  /// **'Skip'**
  String get opsSkip;

  /// S4 title
  ///
  /// In en, this message translates to:
  /// **'Skip {stop}?'**
  String opsSkipTitle(String stop);

  /// S4 body — states who sees the reason
  ///
  /// In en, this message translates to:
  /// **'The students waiting there will be told, and given your reason.'**
  String get opsSkipBody;

  /// S4 reason field label
  ///
  /// In en, this message translates to:
  /// **'Why are you skipping this stop?'**
  String get opsSkipReason;

  /// S4 reason field helper — the server floor, stated up front
  ///
  /// In en, this message translates to:
  /// **'At least 5 characters. This is shown to the students waiting.'**
  String get opsSkipReasonHint;

  /// S4 confirm action
  ///
  /// In en, this message translates to:
  /// **'Skip stop'**
  String get opsSkipConfirm;

  /// Closes the trip
  ///
  /// In en, this message translates to:
  /// **'Complete trip'**
  String get opsComplete;

  /// S3 title
  ///
  /// In en, this message translates to:
  /// **'Complete this trip?'**
  String get opsCompleteTitle;

  /// S3 body — what closing costs
  ///
  /// In en, this message translates to:
  /// **'The trip will be closed and the office notified. You cannot record anything against it afterwards.'**
  String get opsCompleteBody;

  /// M4 reconciled — the difference is shown, never smoothed over
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 boarding could not be applied} other{{count} boardings could not be applied}}'**
  String opsRejected(int count);

  /// GPS pill — fixes are reaching the server
  ///
  /// In en, this message translates to:
  /// **'Position sharing'**
  String get gpsLive;

  /// GPS pill — waiting for the first fix
  ///
  /// In en, this message translates to:
  /// **'Finding position'**
  String get gpsAcquiring;

  /// GPS pill — the device itself has no fix
  ///
  /// In en, this message translates to:
  /// **'No position signal'**
  String get gpsNoSignal;

  /// GPS pill — permission refused or location services disabled
  ///
  /// In en, this message translates to:
  /// **'Position sharing is off'**
  String get gpsDenied;

  /// GPS pill — fixes held on the phone until the server takes them
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 position saved to send} other{{count} positions saved to send}}'**
  String gpsBuffering(int count);

  /// Screen-reader label for the GPS pill, so colour is never the only carrier
  ///
  /// In en, this message translates to:
  /// **'Position status: {status}'**
  String gpsSemantics(String status);

  /// E2 — location is off or refused
  ///
  /// In en, this message translates to:
  /// **'This trip cannot be tracked'**
  String get gpsDeniedTitle;

  /// E2 body — states the consequence without blocking the trip
  ///
  /// In en, this message translates to:
  /// **'The office cannot see where the bus is. Your trip is not affected and nothing you record is lost.'**
  String get gpsDeniedBody;

  /// E2 recovery action
  ///
  /// In en, this message translates to:
  /// **'Open settings'**
  String get gpsOpenSettings;

  /// C3 — queued, not yet sent. Never described as failed
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 change waiting to send} other{{count} changes waiting to send}}'**
  String syncPending(int count);

  /// C3 — a replay pass is running
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{Sending 1 change} other{Sending {count} changes}}'**
  String syncSending(int count);

  /// C3 — permanently refused by the server
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 change could not be applied} other{{count} changes could not be applied}}'**
  String syncFailed(int count);

  /// C3 action on the failed banner
  ///
  /// In en, this message translates to:
  /// **'Retry now'**
  String get syncRetry;

  /// R1 blocked — the one reason a driver can act on
  ///
  /// In en, this message translates to:
  /// **'Start inspection'**
  String get tripStartInspection;

  /// R1 ready — the primary action, 64dp
  ///
  /// In en, this message translates to:
  /// **'START TRIP'**
  String get tripStart;

  /// A caution beside START TRIP, never a gate. The attempt is what proves reachability
  ///
  /// In en, this message translates to:
  /// **'No connection right now — starting may not get through.'**
  String get tripStartOffline;

  /// S2 title
  ///
  /// In en, this message translates to:
  /// **'Start this trip?'**
  String get tripStartConfirmTitle;

  /// S2 body — what starting causes outside the phone
  ///
  /// In en, this message translates to:
  /// **'Students will be told the bus is on its way, and this phone will share its position with the office until you complete the trip.'**
  String get tripStartConfirmBody;

  /// S2 confirm action
  ///
  /// In en, this message translates to:
  /// **'Start trip'**
  String get tripStartConfirm;

  /// S2 dismiss action
  ///
  /// In en, this message translates to:
  /// **'Not yet'**
  String get tripStartCancel;

  /// P9 title
  ///
  /// In en, this message translates to:
  /// **'Pre-trip inspection'**
  String get inspectionTitle;

  /// How many checklist items are answered
  ///
  /// In en, this message translates to:
  /// **'{answered} of {total}'**
  String inspectionProgress(int answered, int total);

  /// Odometer field label
  ///
  /// In en, this message translates to:
  /// **'Odometer reading (km)'**
  String get inspectionOdometer;

  /// Stated before any error, not after
  ///
  /// In en, this message translates to:
  /// **'Must be at least {value} km'**
  String inspectionOdometerMinimum(String value);

  /// The reading the driver entered, shown on review
  ///
  /// In en, this message translates to:
  /// **'Odometer: {value} km'**
  String inspectionOdometerReading(String value);

  /// Odometer missing
  ///
  /// In en, this message translates to:
  /// **'Enter the odometer reading'**
  String get inspectionOdometerRequired;

  /// Checklist verdict
  ///
  /// In en, this message translates to:
  /// **'Pass'**
  String get inspectionPass;

  /// Checklist verdict
  ///
  /// In en, this message translates to:
  /// **'Fail'**
  String get inspectionFail;

  /// Marker on items that ground the bus
  ///
  /// In en, this message translates to:
  /// **'Safety critical'**
  String get inspectionSafetyCritical;

  /// Notes field on a failed item
  ///
  /// In en, this message translates to:
  /// **'What did you find?'**
  String get inspectionNotesLabel;

  /// Notes missing or too short
  ///
  /// In en, this message translates to:
  /// **'Describe what you found'**
  String get inspectionNotesRequired;

  /// Safety-critical failure needs evidence
  ///
  /// In en, this message translates to:
  /// **'A photograph is required for {item}'**
  String inspectionEvidenceRequired(String item);

  /// Move to P10
  ///
  /// In en, this message translates to:
  /// **'Review'**
  String get inspectionReview;

  /// Review is disabled and carries the remaining count
  ///
  /// In en, this message translates to:
  /// **'Review ({count} left)'**
  String inspectionReviewRemaining(int count);

  /// P10 title
  ///
  /// In en, this message translates to:
  /// **'Review and submit'**
  String get inspectionReviewTitle;

  /// P10 submit
  ///
  /// In en, this message translates to:
  /// **'Submit inspection'**
  String get inspectionSubmit;

  /// P10 back to P9
  ///
  /// In en, this message translates to:
  /// **'Back to checklist'**
  String get inspectionBack;

  /// Consequence panel title before submitting a critical failure
  ///
  /// In en, this message translates to:
  /// **'This will take the bus out of service'**
  String get inspectionGroundedTitle;

  /// Consequence panel body
  ///
  /// In en, this message translates to:
  /// **'A maintenance ticket will be opened. You will not be able to start this trip.'**
  String get inspectionGroundedBody;

  /// Summary line on the review screen
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =0{Nothing failed} =1{1 item failed} other{{count} items failed}}'**
  String inspectionPassedSummary(int count);

  /// D1 confirmation title
  ///
  /// In en, this message translates to:
  /// **'Discard inspection?'**
  String get inspectionDiscardTitle;

  /// D1 confirmation body
  ///
  /// In en, this message translates to:
  /// **'Everything you have entered will be lost.'**
  String get inspectionDiscardBody;

  /// D1 confirm
  ///
  /// In en, this message translates to:
  /// **'Discard'**
  String get inspectionDiscard;

  /// D1 cancel
  ///
  /// In en, this message translates to:
  /// **'Keep editing'**
  String get inspectionKeep;

  /// P9 error card
  ///
  /// In en, this message translates to:
  /// **'Could not load the checklist'**
  String get inspectionUnavailableTitle;

  /// A checklist with no items is a server fault
  ///
  /// In en, this message translates to:
  /// **'The server returned no checklist items. Contact the transport office — this inspection cannot be completed.'**
  String get inspectionEmptyChecklist;

  /// Offline submit outcome
  ///
  /// In en, this message translates to:
  /// **'Saved — not yet submitted'**
  String get inspectionSavedTitle;

  /// States plainly that the bus is not cleared
  ///
  /// In en, this message translates to:
  /// **'This inspection will submit when you have signal. The bus is not cleared until it does.'**
  String get inspectionSavedBody;

  /// P11 outcome PASSED
  ///
  /// In en, this message translates to:
  /// **'Cleared'**
  String get inspectionResultPassed;

  /// P11 outcome PASSED_WITH_DEFECTS
  ///
  /// In en, this message translates to:
  /// **'Passed with defects'**
  String get inspectionResultDefects;

  /// P11 outcome FAILED
  ///
  /// In en, this message translates to:
  /// **'Bus out of service'**
  String get inspectionResultFailed;

  /// Shown when the outcome opened a ticket
  ///
  /// In en, this message translates to:
  /// **'A maintenance ticket has been opened.'**
  String get inspectionTicketOpened;

  /// P11 exit
  ///
  /// In en, this message translates to:
  /// **'Back to trip'**
  String get inspectionDone;

  /// EvidenceCard, nothing captured
  ///
  /// In en, this message translates to:
  /// **'No photograph yet'**
  String get evidenceEmpty;

  /// EvidenceCard, camera open
  ///
  /// In en, this message translates to:
  /// **'Opening the camera…'**
  String get evidenceCapturing;

  /// EvidenceCard, captured and awaiting confirmation
  ///
  /// In en, this message translates to:
  /// **'Use this photograph?'**
  String get evidencePreview;

  /// EvidenceCard, upload in flight
  ///
  /// In en, this message translates to:
  /// **'Sending the photograph…'**
  String get evidenceUploading;

  /// EvidenceCard, server confirmed
  ///
  /// In en, this message translates to:
  /// **'Photograph attached'**
  String get evidenceUploaded;

  /// EvidenceCard, captured offline; the id does not exist yet
  ///
  /// In en, this message translates to:
  /// **'Photograph saved — not yet sent'**
  String get evidenceQueued;

  /// Says plainly that a queued photograph blocks submission
  ///
  /// In en, this message translates to:
  /// **'It will be sent when you have signal. The inspection cannot be submitted until then.'**
  String get evidenceQueuedDetail;

  /// EvidenceCard, server refused the file
  ///
  /// In en, this message translates to:
  /// **'The photograph was refused'**
  String get evidenceRejected;

  /// EvidenceCard, camera permission denied
  ///
  /// In en, this message translates to:
  /// **'The camera is switched off for this app'**
  String get evidenceBlocked;

  /// States plainly what cannot happen without the camera
  ///
  /// In en, this message translates to:
  /// **'A failing safety check cannot be completed without a photograph.'**
  String get evidenceBlockedDetail;

  /// EvidenceCard capture action
  ///
  /// In en, this message translates to:
  /// **'Take photograph'**
  String get evidenceTake;

  /// EvidenceCard retake action
  ///
  /// In en, this message translates to:
  /// **'Retake'**
  String get evidenceRetake;

  /// EvidenceCard confirm action — uploads on confirm, not on capture
  ///
  /// In en, this message translates to:
  /// **'Use photograph'**
  String get evidenceUse;

  /// For a permanently denied camera permission
  ///
  /// In en, this message translates to:
  /// **'Open settings'**
  String get evidenceOpenSettings;

  /// P9 title, in a driver's words
  ///
  /// In en, this message translates to:
  /// **'Pre-trip check'**
  String get quickTitle;

  /// Label above the registration number
  ///
  /// In en, this message translates to:
  /// **'Bus'**
  String get quickBus;

  /// The bus's recorded odometer, offered for confirmation
  ///
  /// In en, this message translates to:
  /// **'{value} km'**
  String quickOdometerReading(String value);

  /// Accepts the pre-filled odometer without typing
  ///
  /// In en, this message translates to:
  /// **'This is correct'**
  String get quickOdometerCorrect;

  /// Opens the odometer for typing
  ///
  /// In en, this message translates to:
  /// **'Edit'**
  String get quickOdometerEdit;

  /// Accepts a typed odometer
  ///
  /// In en, this message translates to:
  /// **'Continue'**
  String get quickOdometerContinue;

  /// Shown when the API supplied no recorded total
  ///
  /// In en, this message translates to:
  /// **'Enter the odometer reading'**
  String get quickOdometerUnknown;

  /// The question above the ALL OK action
  ///
  /// In en, this message translates to:
  /// **'Have you checked the bus?'**
  String get quickPrompt;

  /// The one deliberate action for a normal check
  ///
  /// In en, this message translates to:
  /// **'ALL OK'**
  String get quickAllOk;

  /// Screen-reader meaning of the ALL OK action
  ///
  /// In en, this message translates to:
  /// **'All OK. Marks every check as passed.'**
  String get quickAllOkSemantics;

  /// Opens the item list to report an exception
  ///
  /// In en, this message translates to:
  /// **'Something wrong?'**
  String get quickSomethingWrong;

  /// Counted from the server checklist, never a constant
  ///
  /// In en, this message translates to:
  /// **'{passed} of {total} checks OK'**
  String quickChecksOk(int passed, int total);

  /// How many items the driver marked as not OK
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 issue} other{{count} issues}}'**
  String quickIssues(int count);

  /// Heading on the compact confirmation
  ///
  /// In en, this message translates to:
  /// **'Inspection ready'**
  String get quickReady;

  /// Primary action on the confirmation
  ///
  /// In en, this message translates to:
  /// **'Confirm & submit'**
  String get quickConfirmSubmit;

  /// Returns from the confirmation to the check
  ///
  /// In en, this message translates to:
  /// **'Go back'**
  String get quickGoBack;

  /// Marks one item as the exception
  ///
  /// In en, this message translates to:
  /// **'Not OK'**
  String get quickNotOk;

  /// An item that is fine
  ///
  /// In en, this message translates to:
  /// **'OK'**
  String get quickItemOk;

  /// Heading above the revealed item list
  ///
  /// In en, this message translates to:
  /// **'What is wrong?'**
  String get quickWhatIsWrong;

  /// Shown on the review for an evidenced failure
  ///
  /// In en, this message translates to:
  /// **'Photo attached'**
  String get quickPhotoAttached;

  /// The server accepted the inspection
  ///
  /// In en, this message translates to:
  /// **'Submitted'**
  String get quickSubmitted;

  /// Offline — deliberately not the word submitted
  ///
  /// In en, this message translates to:
  /// **'Saved on this device'**
  String get quickSavedHere;
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
