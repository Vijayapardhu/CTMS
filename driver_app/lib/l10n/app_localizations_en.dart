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
  String get errorTitle => 'Request failed';

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
  String get settingsAbout => 'About';

  @override
  String aboutVersion(String version, String build) {
    return 'Version $version ($build)';
  }

  @override
  String aboutEnvironment(String flavor, String host) {
    return '$flavor build · $host';
  }

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
  String get loginOffline => 'Offline — signing in needs the CTMS server.';

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
      'This is not the same as having no trip. The request failed — try again.';

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
  String get tripStartWindowWaiting =>
      'Start will unlock by itself. You do not need to do anything.';

  @override
  String get mapIdleTitle => 'No trip is running';

  @override
  String get mapIdleBody => 'The map shows the bus once a trip has started.';

  @override
  String get mapBusMarker => 'Your bus';

  @override
  String get mapRecentre => 'Centre on the bus';

  @override
  String mapPositionAge(int minutes) {
    String _temp0 = intl.Intl.pluralLogic(
      minutes,
      locale: localeName,
      other: 'Position $minutes minutes old',
      one: 'Position 1 minute old',
    );
    return '$_temp0';
  }

  @override
  String get mapPositionStale => 'Location may be outdated';

  @override
  String get mapPollFailed => 'Not updating — showing the last known position';

  @override
  String get mapUnavailableTitle => 'Map unavailable';

  @override
  String get mapUnavailableBody =>
      'The map could not load. Tracking is unaffected — your position is still being sent.';

  @override
  String get mapNextStop => 'NEXT STOP';

  @override
  String get mapUnnamedStop => 'Next stop';

  @override
  String mapAtStop(String stop) {
    return 'At $stop';
  }

  @override
  String get mapNoMoreStops => 'No stops remaining';

  @override
  String mapEtaMinutes(String clock) {
    return '$clock';
  }

  @override
  String mapEtaStale(String clock) {
    return 'About $clock — not updating';
  }

  @override
  String mapEtaScheduled(String clock) {
    return '$clock by timetable';
  }

  @override
  String get mapEtaArrivingNow => 'Arriving now';

  @override
  String get mapEtaScheduledOnly =>
      'Running to timetable — no live estimate yet';

  @override
  String get mapEtaArrived => 'Arrived';

  @override
  String get mapEtaUnavailable => 'No estimate available';

  @override
  String mapStopsAway(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count stops away',
      one: '1 stop away',
    );
    return '$_temp0';
  }

  @override
  String get mapRouteUnavailable =>
      'Route could not be loaded — the bus position is still live.';

  @override
  String get alertsEmptyTitle => 'Nothing from the office';

  @override
  String get alertsEmptyBody => 'Alerts from the transport office appear here.';

  @override
  String get alertsNew => 'NEW';

  @override
  String get alertsMarkAllRead => 'Mark all read';

  @override
  String get alertsStale =>
      'Showing the last alerts received — this could not be refreshed';

  @override
  String get sosTitle => 'Emergency';

  @override
  String get sosPrompt => 'Hold the button to alert the transport office.';

  @override
  String get sosHold => 'SOS';

  @override
  String get sosHolding => 'Keep holding';

  @override
  String get sosNoDetailsNeeded =>
      'You do not need to type anything. Your bus and position are sent automatically.';

  @override
  String get sosSentTitle => 'Help has been alerted';

  @override
  String get sosSentBody => 'The transport office has your alert.';

  @override
  String get sosQueuedTitle => 'Saved — not yet sent';

  @override
  String get sosQueuedBody =>
      'Your phone is holding this alert and will send it as soon as there is signal. Call the office now if you can.';

  @override
  String get sosNoNumber => 'No emergency number is configured on this device.';

  @override
  String get sosCall => 'Call the office';

  @override
  String get sosSms => 'Send a text with my position';

  @override
  String get sosOpen => 'SOS';

  @override
  String get incidentTitle => 'Report a problem';

  @override
  String get incidentOpen => 'Report a problem';

  @override
  String get incidentWhatHappened => 'What has happened?';

  @override
  String get incidentNeedsPhoto => 'A photograph is required';

  @override
  String get incidentDescription => 'What happened?';

  @override
  String get incidentDescriptionHint => 'A sentence is enough.';

  @override
  String incidentEvidenceRequired(String label) {
    return 'A photograph is required for $label';
  }

  @override
  String get incidentCanContinue => 'The bus can keep going';

  @override
  String get incidentCanContinueHint =>
      'Turn this off if the bus cannot be driven.';

  @override
  String get incidentSubmit => 'Report it';

  @override
  String get incidentReported => 'Reported';

  @override
  String get incidentBusOutOfService => 'Bus out of service';

  @override
  String get incidentMaintenanceOpened =>
      'A maintenance ticket has been opened. Do not continue this trip.';

  @override
  String get incidentBackToTrip => 'Back to trip';

  @override
  String get incidentQueuedTitle => 'Saved — not yet sent';

  @override
  String get incidentQueuedBody =>
      'This report will be sent when you have signal.';

  @override
  String get incidentTypesUnavailableTitle => 'Cannot load the problem list';

  @override
  String get incidentTypesUnavailableBody =>
      'The list of problem types comes from the office and could not be read. Your trip is not affected.';

  @override
  String get opsOnBoard => 'ON BOARD';

  @override
  String get opsBoard => 'ON';

  @override
  String get opsAlight => 'OFF';

  @override
  String opsNotYetSynced(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count not yet sent',
      one: '1 not yet sent',
    );
    return '$_temp0';
  }

  @override
  String opsArrivedAt(String stop) {
    return 'I have arrived at $stop';
  }

  @override
  String opsAtStopNow(String stop) {
    return 'You are at $stop';
  }

  @override
  String opsDistanceToStop(String distance, String stop) {
    return '$distance to $stop';
  }

  @override
  String get opsArrived => 'Arrived';

  @override
  String get opsSkip => 'Skip';

  @override
  String opsSkipTitle(String stop) {
    return 'Skip $stop?';
  }

  @override
  String get opsSkipBody =>
      'The students waiting there will be told, and given your reason.';

  @override
  String get opsSkipReason => 'Why are you skipping this stop?';

  @override
  String get opsSkipReasonHint =>
      'At least 5 characters. This is shown to the students waiting.';

  @override
  String get opsSkipConfirm => 'Skip stop';

  @override
  String get opsComplete => 'Complete trip';

  @override
  String get opsCompleteTitle => 'Complete this trip?';

  @override
  String get opsCompleteBody =>
      'The trip will be closed and the office notified. You cannot record anything against it afterwards.';

  @override
  String opsRejected(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count boardings could not be applied',
      one: '1 boarding could not be applied',
    );
    return '$_temp0';
  }

  @override
  String get gpsLive => 'Position sharing';

  @override
  String get gpsAcquiring => 'Finding position';

  @override
  String get gpsNoSignal => 'No position signal';

  @override
  String get gpsDenied => 'Position sharing is off';

  @override
  String gpsBuffering(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count positions saved to send',
      one: '1 position saved to send',
    );
    return '$_temp0';
  }

  @override
  String gpsSemantics(String status) {
    return 'Position status: $status';
  }

  @override
  String get gpsDeniedTitle => 'This trip cannot be tracked';

  @override
  String get gpsDeniedBody =>
      'The office cannot see where the bus is. Your trip is not affected and nothing you record is lost.';

  @override
  String get gpsOpenSettings => 'Open settings';

  @override
  String syncPending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count changes waiting to send',
      one: '1 change waiting to send',
    );
    return '$_temp0';
  }

  @override
  String syncSending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Sending $count changes',
      one: 'Sending 1 change',
    );
    return '$_temp0';
  }

  @override
  String syncFailed(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count changes could not be applied',
      one: '1 change could not be applied',
    );
    return '$_temp0';
  }

  @override
  String get syncRetry => 'Retry now';

  @override
  String get tripStartInspection => 'Start inspection';

  @override
  String get tripStart => 'START TRIP';

  @override
  String get tripStartOffline => 'Offline — starting may not get through.';

  @override
  String get tripStartConfirmTitle => 'Start this trip?';

  @override
  String get tripStartConfirmBody =>
      'Students will be told the bus is on its way, and this phone will share its position with the office until you complete the trip.';

  @override
  String get tripStartConfirm => 'Start trip';

  @override
  String get tripStartCancel => 'Not yet';

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

  @override
  String get evidenceEmpty => 'No photograph yet';

  @override
  String get evidenceCapturing => 'Opening the camera…';

  @override
  String get evidencePreview => 'Use this photograph?';

  @override
  String get evidenceUploading => 'Sending the photograph…';

  @override
  String get evidenceUploaded => 'Photograph attached';

  @override
  String get evidenceQueued => 'Photograph saved — not yet sent';

  @override
  String get evidenceQueuedDetail =>
      'It will be sent when you have signal. The inspection cannot be submitted until then.';

  @override
  String get evidenceRejected => 'The photograph was refused';

  @override
  String get evidenceBlocked => 'The camera is switched off for this app';

  @override
  String get evidenceBlockedDetail =>
      'Camera access is needed to attach photographs to inspections and incident reports. Without one, a failing safety check cannot be completed.';

  @override
  String get evidenceBlockedPermanently =>
      'Photographs are needed to evidence a failing check, and a failing safety check cannot be completed without one. Turn the camera on in Settings.';

  @override
  String get evidenceBlockedUnavailable =>
      'This phone cannot use its camera for CTMS. Report the fault to the office instead.';

  @override
  String get evidenceTake => 'Take photograph';

  @override
  String get evidenceRetake => 'Retake';

  @override
  String get evidenceUse => 'Use photograph';

  @override
  String get evidenceOpenSettings => 'Open settings';

  @override
  String get quickTitle => 'Pre-trip check';

  @override
  String get quickBus => 'Bus';

  @override
  String quickOdometerReading(String value) {
    return '$value km';
  }

  @override
  String get quickOdometerCorrect => 'This is correct';

  @override
  String get quickOdometerEdit => 'Edit';

  @override
  String get quickOdometerContinue => 'Continue';

  @override
  String get quickOdometerUnknown => 'Enter the odometer reading';

  @override
  String get quickPrompt => 'Have you checked the bus?';

  @override
  String get quickAllOk => 'ALL OK';

  @override
  String get quickAllOkSemantics => 'All OK. Marks every check as passed.';

  @override
  String get quickSomethingWrong => 'Something wrong?';

  @override
  String quickChecksOk(int passed, int total) {
    return '$passed of $total checks OK';
  }

  @override
  String quickIssues(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count issues',
      one: '1 issue',
    );
    return '$_temp0';
  }

  @override
  String get quickReady => 'Inspection ready';

  @override
  String get quickConfirmSubmit => 'Confirm & submit';

  @override
  String get quickGoBack => 'Go back';

  @override
  String get quickNotOk => 'Not OK';

  @override
  String get quickItemOk => 'OK';

  @override
  String get quickWhatIsWrong => 'What is wrong?';

  @override
  String get quickPhotoAttached => 'Photo attached';

  @override
  String get quickSubmitted => 'Submitted';

  @override
  String get quickSavedHere => 'Saved on this device';
}
