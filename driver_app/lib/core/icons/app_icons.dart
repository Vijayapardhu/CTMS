import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

/// Every icon in the app, declared once.
///
/// No widget references `HugeIcons.*` or `Icons.*` directly. When a symbol is
/// renamed in a package upgrade, exactly one line in this file changes and no
/// screen is touched.
///
/// [fallback] is a Material symbol verified to exist. It is not decoration: it
/// is what renders if the preferred symbol ever resolves to null, which is what
/// stops a package change becoming a blank square on a driver's screen.
///
/// hugeicons 1.1.7 ships its symbols as SVG path data (`List<List<dynamic>>`)
/// rendered by [HugeIcon], not as a font [IconData]. That is why [preferred]
/// and [fallback] have different types and why [AppIconView] chooses between
/// two widgets rather than two glyphs.
///
/// Style discipline, from `docs/driver-app/08-icon-registry.md`: **Stroke
/// Rounded only**. Never Solid, Bulk, Duotone or Twotone. Material fallbacks
/// use the Rounded set, which is the closest visual match.
///
/// Every name below was verified against hugeicons 1.1.7 during Slice 0 by
/// enumerating the package's 5,167 symbols — not assumed.
/// The shape hugeicons uses for a symbol: a list of drawing commands, each a
/// list of `[tag, attributes]`. Aliased so the type appears once rather than at
/// every use.
typedef HugeIconData = List<List<dynamic>>;

@immutable
class AppIcon {
  const AppIcon._(this.preferred, this.fallback, {required this.semanticLabel});

  /// Builds an icon outside the registry.
  ///
  /// Test-only, and the only way to construct one with a null [preferred] —
  /// which is the fallback path that must be proven to work before a package
  /// rename relies on it.
  @visibleForTesting
  const AppIcon.forTest(
    this.preferred,
    this.fallback, {
    required this.semanticLabel,
  });

  /// The Hugeicons symbol, as SVG path data. Nullable so a future rename
  /// degrades to the Material fallback rather than leaving a blank square.
  final HugeIconData? preferred;

  /// A Material symbol verified to exist.
  final IconData fallback;

  /// Announced by screen readers. An icon-only control without one is a bug.
  final String semanticLabel;

  // ── Navigation ─────────────────────────────────────────────────────────
  static const trip = AppIcon._(HugeIcons.strokeRoundedBus01,
      Icons.directions_bus_rounded, semanticLabel: 'Trip');
  static const map = AppIcon._(HugeIcons.strokeRoundedNavigation03,
      Icons.navigation_rounded, semanticLabel: 'Map');
  static const alerts = AppIcon._(HugeIcons.strokeRoundedNotification03,
      Icons.notifications_rounded, semanticLabel: 'Alerts');
  static const profile = AppIcon._(HugeIcons.strokeRoundedUser,
      Icons.person_rounded, semanticLabel: 'Me');
  static const settings = AppIcon._(HugeIcons.strokeRoundedSettings01,
      Icons.settings_rounded, semanticLabel: 'Settings');
  static const back = AppIcon._(HugeIcons.strokeRoundedArrowLeft01,
      Icons.arrow_back_rounded, semanticLabel: 'Back');
  static const close = AppIcon._(HugeIcons.strokeRoundedCancel01,
      Icons.close_rounded, semanticLabel: 'Close');
  static const chevron = AppIcon._(HugeIcons.strokeRoundedArrowRight01,
      Icons.chevron_right_rounded, semanticLabel: 'Open');

  // ── Trip lifecycle ─────────────────────────────────────────────────────
  static const tripStart = AppIcon._(HugeIcons.strokeRoundedPlayCircle,
      Icons.play_circle_rounded, semanticLabel: 'Start trip');
  static const tripEnd = AppIcon._(HugeIcons.strokeRoundedStopCircle,
      Icons.stop_circle_rounded, semanticLabel: 'End trip');
  static const route = AppIcon._(HugeIcons.strokeRoundedRoute01,
      Icons.route_rounded, semanticLabel: 'Route');
  static const stop = AppIcon._(HugeIcons.strokeRoundedLocation01,
      Icons.place_rounded, semanticLabel: 'Stop');
  static const destination = AppIcon._(HugeIcons.strokeRoundedFlag02,
      Icons.flag_rounded, semanticLabel: 'Destination');
  static const eta = AppIcon._(HugeIcons.strokeRoundedTimer02,
      Icons.timer_rounded, semanticLabel: 'Estimated arrival');
  static const schedule = AppIcon._(HugeIcons.strokeRoundedCalendar03,
      Icons.calendar_today_rounded, semanticLabel: 'Schedule');

  // ── GPS and connectivity ───────────────────────────────────────────────
  static const gpsLive = AppIcon._(HugeIcons.strokeRoundedGps01,
      Icons.gps_fixed_rounded, semanticLabel: 'GPS live');
  static const gpsAcquiring = AppIcon._(HugeIcons.strokeRoundedGps02,
      Icons.gps_not_fixed_rounded, semanticLabel: 'Finding position');
  static const gpsOff = AppIcon._(HugeIcons.strokeRoundedGpsOff01,
      Icons.gps_off_rounded, semanticLabel: 'No position');
  static const offline = AppIcon._(HugeIcons.strokeRoundedCloudOff,
      Icons.cloud_off_rounded, semanticLabel: 'Offline');
  static const online = AppIcon._(HugeIcons.strokeRoundedWifi01,
      Icons.wifi_rounded, semanticLabel: 'Online');
  static const sync = AppIcon._(HugeIcons.strokeRoundedRefresh,
      Icons.sync_rounded, semanticLabel: 'Syncing');
  static const signalWeak = AppIcon._(HugeIcons.strokeRoundedCellularNetwork,
      Icons.signal_cellular_alt_rounded, semanticLabel: 'Weak signal');

  // ── Boarding ───────────────────────────────────────────────────────────
  static const board = AppIcon._(HugeIcons.strokeRoundedArrowRight01,
      Icons.arrow_forward_rounded, semanticLabel: 'Board a passenger');
  static const alight = AppIcon._(HugeIcons.strokeRoundedArrowLeft01,
      Icons.arrow_back_rounded, semanticLabel: 'Passenger alights');
  static const student = AppIcon._(HugeIcons.strokeRoundedUser,
      Icons.person_rounded, semanticLabel: 'Student');
  static const passengers = AppIcon._(HugeIcons.strokeRoundedUserGroup,
      Icons.groups_rounded, semanticLabel: 'Passengers');
  static const capacity = AppIcon._(HugeIcons.strokeRoundedChartBarLine,
      Icons.bar_chart_rounded, semanticLabel: 'Capacity');
  static const leftBehind = AppIcon._(HugeIcons.strokeRoundedUserRemove01,
      Icons.person_remove_rounded, semanticLabel: 'Left behind');

  // ── Inspection ─────────────────────────────────────────────────────────
  static const checklist = AppIcon._(HugeIcons.strokeRoundedCheckList,
      Icons.checklist_rounded, semanticLabel: 'Inspection');
  static const pass = AppIcon._(HugeIcons.strokeRoundedCheckmarkCircle02,
      Icons.check_circle_rounded, semanticLabel: 'Passed');
  static const fail = AppIcon._(HugeIcons.strokeRoundedCancelCircle,
      Icons.cancel_rounded, semanticLabel: 'Failed');
  static const safetyCritical = AppIcon._(HugeIcons.strokeRoundedAlert02,
      Icons.warning_rounded, semanticLabel: 'Safety critical');
  static const camera = AppIcon._(HugeIcons.strokeRoundedCamera01,
      Icons.photo_camera_rounded, semanticLabel: 'Take photograph');
  static const upload = AppIcon._(HugeIcons.strokeRoundedUpload01,
      Icons.upload_rounded, semanticLabel: 'Upload');
  static const evidence = AppIcon._(HugeIcons.strokeRoundedImage01,
      Icons.image_rounded, semanticLabel: 'Photograph');
  static const odometer = AppIcon._(HugeIcons.strokeRoundedDashboardSpeed01,
      Icons.speed_rounded, semanticLabel: 'Odometer');

  // ── Incidents ──────────────────────────────────────────────────────────
  /// The one icon where a wrong glyph is a safety issue: it must read as
  /// *emergency*, not *warning*.
  static const sos = AppIcon._(HugeIcons.strokeRoundedShieldEnergy,
      Icons.emergency_rounded, semanticLabel: 'Emergency alert');
  static const breakdown = AppIcon._(HugeIcons.strokeRoundedAlert01,
      Icons.warning_amber_rounded, semanticLabel: 'Breakdown');
  static const accident = AppIcon._(HugeIcons.strokeRoundedAlertCircle,
      Icons.error_rounded, semanticLabel: 'Accident');
  static const medical = AppIcon._(HugeIcons.strokeRoundedMedicalMask,
      Icons.medical_services_rounded, semanticLabel: 'Medical');
  static const diversion = AppIcon._(HugeIcons.strokeRoundedRouteBlock,
      Icons.alt_route_rounded, semanticLabel: 'Diversion');
  static const replacement = AppIcon._(HugeIcons.strokeRoundedExchange01,
      Icons.swap_horiz_rounded, semanticLabel: 'Replacement bus');
  static const maintenance = AppIcon._(HugeIcons.strokeRoundedWrench01,
      Icons.build_rounded, semanticLabel: 'Maintenance');
  static const fuel = AppIcon._(HugeIcons.strokeRoundedFuelStation,
      Icons.local_gas_station_rounded, semanticLabel: 'Fuel');
  static const tyre = AppIcon._(HugeIcons.strokeRoundedTire,
      Icons.tire_repair_rounded, semanticLabel: 'Tyre');

  // ── Status ─────────────────────────────────────────────────────────────
  static const success = AppIcon._(HugeIcons.strokeRoundedCheckmarkCircle02,
      Icons.check_circle_rounded, semanticLabel: 'Success');
  static const warning = AppIcon._(HugeIcons.strokeRoundedAlert02,
      Icons.warning_rounded, semanticLabel: 'Warning');
  static const error = AppIcon._(HugeIcons.strokeRoundedAlertCircle,
      Icons.error_rounded, semanticLabel: 'Error');
  static const info = AppIcon._(HugeIcons.strokeRoundedInformationCircle,
      Icons.info_rounded, semanticLabel: 'Information');
  static const pending = AppIcon._(HugeIcons.strokeRoundedClock01,
      Icons.schedule_rounded, semanticLabel: 'Pending');
  static const blocked = AppIcon._(HugeIcons.strokeRoundedMinusSignCircle,
      Icons.do_not_disturb_on_rounded, semanticLabel: 'Blocked');

  // ── Actions ────────────────────────────────────────────────────────────
  static const call = AppIcon._(HugeIcons.strokeRoundedCall02,
      Icons.call_rounded, semanticLabel: 'Call');
  static const sms = AppIcon._(HugeIcons.strokeRoundedMessage01,
      Icons.sms_rounded, semanticLabel: 'Send SMS');
  static const retry = AppIcon._(HugeIcons.strokeRoundedRefresh,
      Icons.refresh_rounded, semanticLabel: 'Retry');
  static const history = AppIcon._(HugeIcons.strokeRoundedClock04,
      Icons.history_rounded, semanticLabel: 'History');
  static const document = AppIcon._(HugeIcons.strokeRoundedFile01,
      Icons.description_rounded, semanticLabel: 'Document');
  static const help = AppIcon._(HugeIcons.strokeRoundedHelpCircle,
      Icons.help_rounded, semanticLabel: 'Help');
  static const logout = AppIcon._(HugeIcons.strokeRoundedLogout03,
      Icons.logout_rounded, semanticLabel: 'Sign out');

  // ── Session ────────────────────────────────────────────────────────────
  static const login = AppIcon._(HugeIcons.strokeRoundedLogin03,
      Icons.login_rounded, semanticLabel: 'Sign in');
  static const passwordShow = AppIcon._(HugeIcons.strokeRoundedView,
      Icons.visibility_rounded, semanticLabel: 'Show password');
  static const passwordHide = AppIcon._(HugeIcons.strokeRoundedViewOff,
      Icons.visibility_off_rounded, semanticLabel: 'Hide password');
  static const sessionEnded = AppIcon._(HugeIcons.strokeRoundedSquareLock01,
      Icons.lock_rounded, semanticLabel: 'Session ended');
  static const account = AppIcon._(HugeIcons.strokeRoundedUserCircle,
      Icons.account_circle_rounded, semanticLabel: 'Account');

  /// Every icon, for the registry integrity test.
  static const List<AppIcon> all = [
    trip, map, alerts, profile, settings, back, close, chevron,
    tripStart, tripEnd, route, stop, destination, eta, schedule,
    gpsLive, gpsAcquiring, gpsOff, offline, online, sync, signalWeak,
    board, alight, student, passengers, capacity, leftBehind,
    checklist, pass, fail, safetyCritical, camera, upload, evidence, odometer,
    sos, breakdown, accident, medical, diversion, replacement, maintenance,
    fuel, tyre,
    success, warning, error, info, pending, blocked,
    call, sms, retry, history, document, help, logout,
    login, passwordShow, passwordHide, sessionEnded, account,
  ];
}

/// Icon sizes actually used by components.
///
/// Six values. `18` and `22` are omitted because nothing needed them, and two
/// near-identical sizes in a system get used interchangeably.
abstract final class IconSize {
  /// Inside chips, inline markers.
  static const double xs = 16;

  /// Status icons, list leading, pills.
  static const double sm = 20;

  /// Default: navigation, buttons, list items.
  static const double md = 24;

  /// Dashboard cards, incident types.
  static const double lg = 28;

  /// Counter buttons.
  static const double xl = 32;

  /// SOS only.
  static const double sos = 36;
}

/// Renders an [AppIcon]. Widgets use this rather than [Icon] directly, so the
/// semantic label can never be forgotten.
class AppIconView extends StatelessWidget {
  const AppIconView(
    this.icon, {
    this.size = IconSize.md,
    this.color,
    this.excludeSemantics = false,
    super.key,
  });

  final AppIcon icon;
  final double size;
  final Color? color;

  /// Set only where the icon is genuinely decorative and adjacent text already
  /// carries the meaning.
  final bool excludeSemantics;

  @override
  Widget build(BuildContext context) {
    final resolved = color ?? IconTheme.of(context).color;
    final glyph = icon.preferred;

    // [HugeIcon] renders an SVG and carries no semantics of its own, so the
    // label is attached here rather than passed down.
    final child = glyph == null
        ? Icon(icon.fallback, size: size, color: resolved)
        : HugeIcon(icon: glyph, size: size, color: resolved);

    if (excludeSemantics) return ExcludeSemantics(child: child);

    return Semantics(
      label: icon.semanticLabel,
      image: true,
      child: ExcludeSemantics(child: child),
    );
  }
}
