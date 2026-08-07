import 'package:flutter/material.dart';

/// Design tokens for the CTMS Driver App.
///
/// Every value here comes from `docs/driver-app/07-design-system.md` and is
/// used by at least one component in Phase 6. Tokens nothing uses are not
/// defined, because an unused token eventually becomes a wrongly-used one.
///
/// The reference environment is a windscreen at midday, not an office, which
/// is why contrast targets exceed WCAG AA and why nothing is smaller than
/// 12sp or 48dp.
abstract final class Spacing {
  /// Icon-to-label, chip padding.
  static const double xs = 4;

  /// Between related controls. The minimum gap between two touch targets.
  static const double sm = 8;

  /// Default. Screen padding, card padding, gap between cards.
  static const double md = 16;

  /// Between sections.
  static const double lg = 24;

  /// Above a primary action, around empty states.
  static const double xl = 32;

  /// Top of empty states, around the SOS control.
  static const double xxl = 48;
}

/// Corner radii. Four values; nothing needed a fifth.
abstract final class Radii {
  /// Chips, input fields, small tiles.
  static const double sm = 8;

  /// Cards, buttons, evidence thumbnails.
  static const double md = 16;

  /// Sheets (top corners only) and dialogs.
  static const double lg = 28;

  /// Pills, badges, the SOS progress ring.
  static const double full = 999;
}

/// Minimum sizes, driven by the driver's context rather than by a style guide.
///
/// These are floors, not suggestions. A driver may be standing, gloved, and in
/// a vehicle that is about to move.
abstract final class Sizes {
  /// Material's floor, and the minimum for gloved use.
  static const double touchTarget = 48;

  /// Standard button height.
  static const double buttonHeight = 56;

  /// Start trip, submit, send alert — found without looking.
  static const double buttonProminent = 64;

  /// Board and alight. Used hundreds of times per shift, one-handed.
  static const double counterButton = 96;

  /// The SOS control's visual size; its hit area is larger (see Phase 10).
  static const double sosControl = 56;

  /// SOS accepts a hold that drifts, because a bus moves.
  static const double sosHitSlop = 24;

  /// Beyond this width, text stops being glanceable.
  static const double maxLineLength = 560;

  /// Two-pane layouts begin here.
  static const double tabletBreakpoint = 600;
}

/// Motion durations. Every value is short enough that a driver glancing away
/// and back does not miss the change.
abstract final class Motion {
  static const Duration screenPush = Duration(milliseconds: 300);
  static const Duration screenPop = Duration(milliseconds: 250);
  static const Duration modalOpen = Duration(milliseconds: 250);
  static const Duration modalClose = Duration(milliseconds: 200);
  static const Duration sheetOpen = Duration(milliseconds: 300);
  static const Duration sheetClose = Duration(milliseconds: 200);
  static const Duration dialogOpen = Duration(milliseconds: 200);
  static const Duration bannerIn = Duration(milliseconds: 200);
  static const Duration bannerOut = Duration(milliseconds: 150);

  /// The passenger count slides; it never crossfades.
  static const Duration counter = Duration(milliseconds: 180);

  /// Slow on purpose — a fast pulse reads as alarm.
  static const Duration gpsPulse = Duration(milliseconds: 1600);

  /// Must be linear (see [Curves] usage): easing would misrepresent how much
  /// longer the driver has to hold.
  static const Duration sosHold = Duration(milliseconds: 1500);
  static const Duration sosPulse = Duration(milliseconds: 2000);

  static const Duration syncRotation = Duration(milliseconds: 1000);
  static const Duration skeletonShimmer = Duration(milliseconds: 1200);
  static const Duration successDraw = Duration(milliseconds: 400);
  static const Duration errorShake = Duration(milliseconds: 300);
  static const Duration mapCamera = Duration(milliseconds: 600);

  /// Interpolates between fixes so the bus glides rather than jumping.
  static const Duration mapMarker = Duration(milliseconds: 1000);

  static const Duration snackbar = Duration(seconds: 4);
  static const Duration snackbarWithAction = Duration(seconds: 6);
}

/// Curves paired with [Motion].
abstract final class Easing {
  static const Curve standard = Curves.easeInOutCubic;
  static const Curve enter = Curves.easeOutCubic;
  static const Curve exit = Curves.easeOut;

  /// The counter overshoots very slightly so the change is felt.
  static const Curve counter = Curves.easeOutBack;

  /// Progress must be linear to be honest about remaining time.
  static const Curve progress = Curves.linear;

  static const Curve shake = Curves.elasticOut;
}
