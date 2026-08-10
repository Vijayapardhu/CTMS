import 'package:flutter/material.dart';

/// Semantic colours that `ColorScheme` has no slot for.
///
/// `ColorScheme.error` is a single slot, and this app needs four distinct
/// statuses that are all "not primary" — positive, caution, critical and
/// neutral. Bending them into the standard scheme would force a developer to
/// choose between wrong names and wrong colours, so they live in an extension
/// with names that describe meaning rather than appearance.
///
/// Two rules govern every colour here, from
/// `docs/driver-app/07-design-system.md`:
///
/// 1. **Never colour alone.** Each semantic colour is paired with a required
///    icon, because roughly eight percent of male drivers have a colour vision
///    deficiency.
/// 2. **Sunlight first.** Contrast exceeds WCAG AA because the reference
///    environment is a windscreen at midday.
@immutable
class CtmsColors extends ThemeExtension<CtmsColors> {
  const CtmsColors({
    required this.positive,
    required this.onPositive,
    required this.caution,
    required this.onCaution,
    required this.critical,
    required this.onCritical,
    required this.neutral,
    required this.onNeutral,
    required this.info,
    required this.onInfo,
    required this.liveAccent,
    required this.staleAccent,
    required this.emergency,
    required this.onEmergency,
    required this.mapRoute,
    required this.mapRouteDone,
    required this.geofence,
  });

  /// Ready, passed, live GPS, boarded. Paired with a check-circle icon.
  final Color positive;
  final Color onPositive;

  /// Delayed, buffering, defects, pending sync. Paired with alert-triangle.
  final Color caution;
  final Color onCaution;

  /// Blocked, failed, capacity full. Paired with alert-circle.
  final Color critical;
  final Color onCritical;

  /// Offline, disabled, skipped, unknown. Paired with minus-circle.
  ///
  /// Paired like every other tone. In dark the fill is a light grey, and text
  /// that assumed white would sit at under two to one against it.
  final Color neutral;
  final Color onNeutral;

  /// Completed, informational. Paired with information-circle.
  final Color info;
  final Color onInfo;

  /// Position is fresh. Used only for live GPS — never decoratively.
  final Color liveAccent;

  /// Position is stale (`is_stale` from the API). Desaturated on purpose so a
  /// driver cannot mistake old data for current.
  final Color staleAccent;

  /// SOS chrome only.
  ///
  /// Deliberately darker than [critical]: the emergency control must not look
  /// like every other warning in the app, or it stops being findable in a
  /// panic.
  final Color emergency;
  final Color onEmergency;

  final Color mapRoute;
  final Color mapRouteDone;
  final Color geofence;

  static const CtmsColors light = CtmsColors(
    positive: Color(0xFF146C2E),
    onPositive: Color(0xFFFFFFFF),
    caution: Color(0xFF8A5000),
    onCaution: Color(0xFFFFFFFF),
    critical: Color(0xFFB3261E),
    onCritical: Color(0xFFFFFFFF),
    neutral: Color(0xFF5F6368),
    onNeutral: Color(0xFFFFFFFF),
    info: Color(0xFF00639B),
    onInfo: Color(0xFFFFFFFF),
    liveAccent: Color(0xFF00A63E),
    staleAccent: Color(0xFF9AA0A6),
    emergency: Color(0xFF8C1D18),
    onEmergency: Color(0xFFFFFFFF),
    mapRoute: Color(0xB30B57D0),
    mapRouteDone: Color(0x665F6368),
    geofence: Color(0x1F0B57D0),
  );

  static const CtmsColors dark = CtmsColors(
    positive: Color(0xFF7FD98F),
    onPositive: Color(0xFF00390F),
    caution: Color(0xFFFFB868),
    onCaution: Color(0xFF4A2800),
    critical: Color(0xFFFFB4AB),
    onCritical: Color(0xFF690005),
    neutral: Color(0xFF9AA0A6),
    onNeutral: Color(0xFF1F2124),
    info: Color(0xFF8ECFF8),
    onInfo: Color(0xFF003353),
    liveAccent: Color(0xFF5CE07A),
    staleAccent: Color(0xFF6F7378),
    emergency: Color(0xFFF2B8B5),
    onEmergency: Color(0xFF601410),
    mapRoute: Color(0xB3A8C7FA),
    mapRouteDone: Color(0x669AA0A6),
    geofence: Color(0x1FA8C7FA),
  );

  @override
  CtmsColors copyWith({
    Color? positive,
    Color? onPositive,
    Color? caution,
    Color? onCaution,
    Color? critical,
    Color? onCritical,
    Color? neutral,
    Color? onNeutral,
    Color? info,
    Color? onInfo,
    Color? liveAccent,
    Color? staleAccent,
    Color? emergency,
    Color? onEmergency,
    Color? mapRoute,
    Color? mapRouteDone,
    Color? geofence,
  }) {
    return CtmsColors(
      positive: positive ?? this.positive,
      onPositive: onPositive ?? this.onPositive,
      caution: caution ?? this.caution,
      onCaution: onCaution ?? this.onCaution,
      critical: critical ?? this.critical,
      onCritical: onCritical ?? this.onCritical,
      neutral: neutral ?? this.neutral,
      onNeutral: onNeutral ?? this.onNeutral,
      info: info ?? this.info,
      onInfo: onInfo ?? this.onInfo,
      liveAccent: liveAccent ?? this.liveAccent,
      staleAccent: staleAccent ?? this.staleAccent,
      emergency: emergency ?? this.emergency,
      onEmergency: onEmergency ?? this.onEmergency,
      mapRoute: mapRoute ?? this.mapRoute,
      mapRouteDone: mapRouteDone ?? this.mapRouteDone,
      geofence: geofence ?? this.geofence,
    );
  }

  @override
  CtmsColors lerp(ThemeExtension<CtmsColors>? other, double t) {
    if (other is! CtmsColors) return this;

    return CtmsColors(
      positive: Color.lerp(positive, other.positive, t)!,
      onPositive: Color.lerp(onPositive, other.onPositive, t)!,
      caution: Color.lerp(caution, other.caution, t)!,
      onCaution: Color.lerp(onCaution, other.onCaution, t)!,
      critical: Color.lerp(critical, other.critical, t)!,
      onCritical: Color.lerp(onCritical, other.onCritical, t)!,
      neutral: Color.lerp(neutral, other.neutral, t)!,
      onNeutral: Color.lerp(onNeutral, other.onNeutral, t)!,
      info: Color.lerp(info, other.info, t)!,
      onInfo: Color.lerp(onInfo, other.onInfo, t)!,
      liveAccent: Color.lerp(liveAccent, other.liveAccent, t)!,
      staleAccent: Color.lerp(staleAccent, other.staleAccent, t)!,
      emergency: Color.lerp(emergency, other.emergency, t)!,
      onEmergency: Color.lerp(onEmergency, other.onEmergency, t)!,
      mapRoute: Color.lerp(mapRoute, other.mapRoute, t)!,
      mapRouteDone: Color.lerp(mapRouteDone, other.mapRouteDone, t)!,
      geofence: Color.lerp(geofence, other.geofence, t)!,
    );
  }
}

/// Reads [CtmsColors] without the ceremony of `Theme.of(context).extension<…>()!`.
extension CtmsColorsX on BuildContext {
  CtmsColors get ctms => Theme.of(this).extension<CtmsColors>()!;
}
