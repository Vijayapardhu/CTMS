import 'package:flutter/material.dart';

import '../../../../core/design_system/ctms_colors.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../core/widgets/constrained_text_scale.dart';
import '../../../../l10n/app_localizations.dart';
import '../../domain/gps_state.dart';

/// Component 13 — `GpsStatusPill`.
///
/// The driver's only window onto M3. It never blocks, never opens a dialog and
/// never asks for anything: a bus keeps running whether or not the office can
/// see it, and a modal about satellites in traffic is worse than no tracking.
///
/// Colour is never the only carrier — each state has its own icon and its own
/// words, because a pill that is merely amber says nothing to a driver who
/// cannot distinguish it from green.
class GpsStatusPill extends StatelessWidget {
  const GpsStatusPill({required this.state, super.key});

  final GpsState state;

  @override
  Widget build(BuildContext context) {
    if (state is GpsIdle) return const SizedBox.shrink();

    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    final (tone, icon, label) = switch (state) {
      GpsLive() => (colors.positive, AppIcon.gpsLive, strings.gpsLive),
      GpsAcquiring() => (
          theme.colorScheme.onSurfaceVariant,
          AppIcon.gpsAcquiring,
          strings.gpsAcquiring,
        ),
      GpsBuffering(:final count) => (
          colors.caution,
          AppIcon.gpsAcquiring,
          strings.gpsBuffering(count),
        ),
      GpsNoSignal(:final count) => (
          theme.colorScheme.onSurfaceVariant,
          AppIcon.gpsOff,
          count == 0 ? strings.gpsNoSignal : strings.gpsBuffering(count),
        ),
      GpsDenied() => (colors.critical, AppIcon.gpsOff, strings.gpsDenied),
      GpsIdle() => (colors.positive, AppIcon.gpsOff, ''),
    };

    return ConstrainedTextScale(
      child: Semantics(
        liveRegion: true,
        label: strings.gpsSemantics(label),
        excludeSemantics: true,
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: Spacing.md,
            vertical: Spacing.sm,
          ),
          decoration: BoxDecoration(
            color: tone.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(Radii.full),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              AppIconView(icon, size: IconSize.sm, color: tone),
              const SizedBox(width: Spacing.sm),
              Flexible(
                child: Text(
                  label,
                  style: theme.textTheme.labelLarge?.copyWith(color: tone),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
