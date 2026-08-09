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

  @override
  String get tripNoneTitle => 'No trip assigned today';

  @override
  String get tripNoneBody => 'If you expect one, contact the transport office.';

  @override
  String get tripUnavailableTitle => 'Could not load today\'s trip';

  @override
  String get tripUnavailableBody =>
      'This is not the same as having no trip. Check your connection and try again.';

  @override
  String get tripRetry => 'Try again';

  @override
  String get tripStale =>
      'Showing the last known trip — this could not be refreshed';

  @override
  String get tripStatusReady => 'READY';

  @override
  String get tripStatusBlocked => 'NOT READY';

  @override
  String get tripStatusRunning => 'RUNNING';

  @override
  String get tripStatusWaiting => 'WAITING';

  @override
  String get tripStatusCompleted => 'COMPLETED';

  @override
  String get tripStatusCancelled => 'CANCELLED';

  @override
  String get tripReasonsActionable => 'You can fix this';

  @override
  String get tripReasonsBlocking => 'Operations must fix this';

  @override
  String tripDeparture(String time) {
    return 'Departs $time';
  }

  @override
  String tripStopCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count stops',
      one: '1 stop',
    );
    return '$_temp0';
  }

  @override
  String tripExpected(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count students expected',
      one: '1 student expected',
    );
    return '$_temp0';
  }

  @override
  String tripOnBoard(int occupied, int capacity) {
    return '$occupied of $capacity on board';
  }

  @override
  String tripCancelledBecause(String reason) {
    return 'Cancelled: $reason';
  }

  @override
  String get tripAutoClosed => 'This trip was closed automatically.';

  @override
  String tripReadinessCheckedAt(String time) {
    return 'Clearance checked at $time';
  }

  @override
  String tripStartWindowOpens(String time) {
    return 'Start opens at $time';
  }

  @override
  String get tripStartInspection => 'Start inspection';

  @override
  String get inspectionTitle => 'Pre-trip inspection';

  @override
  String inspectionProgress(int answered, int total) {
    return '$answered of $total';
  }

  @override
  String get inspectionOdometer => 'Odometer reading (km)';

  @override
  String inspectionOdometerMinimum(String value) {
    return 'Must be at least $value km';
  }

  @override
  String inspectionOdometerReading(String value) {
    return 'Odometer: $value km';
  }

  @override
  String get inspectionOdometerRequired => 'Enter the odometer reading';

  @override
  String get inspectionPass => 'Pass';

  @override
  String get inspectionFail => 'Fail';

  @override
  String get inspectionSafetyCritical => 'Safety critical';

  @override
  String get inspectionNotesLabel => 'What did you find?';

  @override
  String get inspectionNotesRequired => 'Describe what you found';

  @override
  String inspectionEvidenceRequired(String item) {
    return 'A photograph is required for $item';
  }

  @override
  String get inspectionReview => 'Review';

  @override
  String inspectionReviewRemaining(int count) {
    return 'Review ($count left)';
  }

  @override
  String get inspectionReviewTitle => 'Review and submit';

  @override
  String get inspectionSubmit => 'Submit inspection';

  @override
  String get inspectionBack => 'Back to checklist';

  @override
  String get inspectionGroundedTitle => 'This will take the bus out of service';

  @override
  String get inspectionGroundedBody =>
      'A maintenance ticket will be opened. You will not be able to start this trip.';

  @override
  String inspectionPassedSummary(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count items failed',
      one: '1 item failed',
      zero: 'Nothing failed',
    );
    return '$_temp0';
  }

  @override
  String get inspectionDiscardTitle => 'Discard inspection?';

  @override
  String get inspectionDiscardBody =>
      'Everything you have entered will be lost.';

  @override
  String get inspectionDiscard => 'Discard';

  @override
  String get inspectionKeep => 'Keep editing';

  @override
  String get inspectionUnavailableTitle => 'Could not load the checklist';

  @override
  String get inspectionEmptyChecklist =>
      'The server returned no checklist items. Contact the transport office — this inspection cannot be completed.';

  @override
  String get inspectionSavedTitle => 'Saved — not yet submitted';

  @override
  String get inspectionSavedBody =>
      'This inspection will submit when you have signal. The bus is not cleared until it does.';

  @override
  String get inspectionResultPassed => 'Cleared';

  @override
  String get inspectionResultDefects => 'Passed with defects';

  @override
  String get inspectionResultFailed => 'Bus out of service';

  @override
  String get inspectionTicketOpened => 'A maintenance ticket has been opened.';

  @override
  String get inspectionDone => 'Back to trip';
}
