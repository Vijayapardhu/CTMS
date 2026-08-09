import 'package:flutter/material.dart';

import '../../../../core/design_system/ctms_colors.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../l10n/app_localizations.dart';
import '../../../tracking/domain/live_trip.dart';
import '../../../tracking/presentation/bloc/tracking_bloc.dart';

/// The card under the map: where the bus is going, and when it gets there.
///
/// Kept short and kept low. It is the second thing in the hierarchy after
/// status, and it must not grow tall enough to cover the road ahead of the bus
/// marker — a driver glances at this and looks back up.
class NextStopSheet extends StatelessWidget {
  const NextStopSheet({required this.state, super.key});

  final TrackingState state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final trip = state.trip;

    if (trip == null) return const SizedBox.shrink();

    final next = trip.nextStop;
    final current = trip.currentStop;

    return SafeArea(
      minimum: const EdgeInsets.all(Spacing.md),
      child: Material(
        elevation: 6,
        borderRadius: BorderRadius.circular(Radii.md),
        color: theme.colorScheme.surface,
        child: Padding(
          padding: const EdgeInsets.all(Spacing.md),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (current != null) ...[
                _AtStop(current),
                const Divider(height: Spacing.lg),
              ],
              if (next == null)
                Text(strings.mapNoMoreStops, style: theme.textTheme.titleMedium)
              else
                _NextStop(next: next, eta: state.eta, failed: state.etaFailed),
              if (state.routeFailed) ...[
                const SizedBox(height: Spacing.sm),
                Text(
                  strings.mapRouteUnavailable,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// The bus is standing at a stop right now.
class _AtStop extends StatelessWidget {
  const _AtStop(this.stop);

  final LiveStop stop;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Row(
      children: [
        AppIconView(AppIcon.stop, size: IconSize.sm, color: colors.positive),
        const SizedBox(width: Spacing.sm),
        Expanded(
          child: Text(
            AppStrings.of(context).mapAtStop(stop.name ?? ''),
            style: Theme.of(context)
                .textTheme
                .titleMedium
                ?.copyWith(color: colors.positive),
          ),
        ),
      ],
    );
  }
}

class _NextStop extends StatelessWidget {
  const _NextStop({required this.next, required this.eta, required this.failed});

  final LiveStop next;
  final Eta? eta;
  final bool failed;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          strings.mapNextStop,
          style: theme.textTheme.labelLarge
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        Text(
          next.name ?? strings.mapUnnamedStop,
          style: theme.textTheme.headlineSmall,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        const SizedBox(height: Spacing.xs),
        _EtaLine(eta: eta, failed: failed),
      ],
    );
  }
}

/// The estimate, labelled with how much the server itself trusts it.
///
/// `basis` is not decoration. A scheduled estimate is the timetable and has
/// nothing to do with where the bus actually is; showing it in the same words
/// as a live one would tell a driver the system knows something it does not.
class _EtaLine extends StatelessWidget {
  const _EtaLine({required this.eta, required this.failed});

  final Eta? eta;
  final bool failed;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    if (failed || eta == null) {
      return Text(
        strings.mapEtaUnavailable,
        style: theme.textTheme.bodyMedium
            ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
      );
    }

    final value = eta!;

    if (value.basis == EtaBasis.arrived) {
      return Text(strings.mapEtaArrived,
          style: theme.textTheme.titleMedium?.copyWith(color: colors.positive));
    }

    if (!value.isUsable) {
      return Text(
        value.basis == EtaBasis.scheduled
            ? strings.mapEtaScheduledOnly
            : strings.mapEtaUnavailable,
        style: theme.textTheme.bodyMedium
            ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
      );
    }

    final (label, tone) = switch (value.basis) {
      EtaBasis.live => (strings.mapEtaMinutes(value.minutes!), colors.positive),
      EtaBasis.stale => (
          strings.mapEtaStale(value.minutes!),
          colors.caution,
        ),
      _ => (strings.mapEtaScheduled(value.minutes!), colors.caution),
    };

    return Row(
      children: [
        AppIconView(AppIcon.eta, size: IconSize.sm, color: tone),
        const SizedBox(width: Spacing.sm),
        Flexible(
          child: Text(
            label,
            style: theme.textTheme.titleMedium?.copyWith(color: tone),
          ),
        ),
        if (value.stopsAway != null && value.stopsAway! > 0) ...[
          const SizedBox(width: Spacing.sm),
          Text(
            strings.mapStopsAway(value.stopsAway!),
            style: theme.textTheme.bodyMedium
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ],
      ],
    );
  }
}
