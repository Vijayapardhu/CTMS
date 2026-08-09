import 'package:flutter/material.dart';

import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../core/widgets/status_chip.dart';
import '../../../../l10n/app_localizations.dart';
import '../../domain/trip.dart';
import '../../domain/trip_state.dart';

/// Component 7 — `TripCard`.
///
/// One widget with a state parameter rather than five widgets, because the trip
/// is one object. The registration is the largest thing on it: that is how a
/// driver identifies their bus in a yard of twenty.
class TripCard extends StatelessWidget {
  const TripCard({required this.state, super.key});

  /// Read straight from M1 rather than from a duplicate enum, so the card
  /// cannot disagree with the machine about what is going on.
  final TripState state;

  @override
  Widget build(BuildContext context) {
    final trip = state.trip;
    if (trip == null) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final strings = AppStrings.of(context);
    final (label, tone, icon) = _chip(state, strings);

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(Spacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Text(
                    trip.bus?.registrationNumber ?? '—',
                    style: theme.textTheme.headlineSmall,
                  ),
                ),
                const SizedBox(width: Spacing.sm),
                StatusChip(label: label, tone: tone, icon: icon),
              ],
            ),
            if (trip.route != null) ...[
              const SizedBox(height: Spacing.xs),
              Text(
                trip.route!.label,
                style: theme.textTheme.bodyMedium
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
            ],
            if (trip.scheduledDeparture != null) ...[
              const SizedBox(height: Spacing.sm),
              Text(
                strings.tripDeparture(trip.scheduledDeparture!),
                style: theme.textTheme.bodyMedium,
              ),
            ],
          ],
        ),
      ),
    );
  }

  (String, StatusTone, AppIcon) _chip(TripState state, AppStrings strings) {
    return switch (state) {
      TripReady() => (strings.tripStatusReady, StatusTone.positive, AppIcon.success),
      TripBlocked() => (strings.tripStatusBlocked, StatusTone.critical, AppIcon.blocked),
      TripWaiting() => (strings.tripStatusWaiting, StatusTone.caution, AppIcon.pending),
      TripRunning() => (strings.tripStatusRunning, StatusTone.positive, AppIcon.gpsLive),
      TripClosed(:final value) => value.status == TripStatus.cancelled
          ? (strings.tripStatusCancelled, StatusTone.neutral, AppIcon.blocked)
          : (strings.tripStatusCompleted, StatusTone.info, AppIcon.success),
      // States that hold no trip never reach here — the card returns early.
      TripLoading() || TripNone() || TripUnavailable() => (
          strings.tripStatusBlocked,
          StatusTone.neutral,
          AppIcon.blocked,
        ),
    };
  }
}
