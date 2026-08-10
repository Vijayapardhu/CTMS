import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/design_system/ctms_colors.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../core/widgets/big_number_display.dart';
import '../../../../core/widgets/confirm_sheet.dart';
import '../../../../core/widgets/counter_button.dart';
import '../../../../l10n/app_localizations.dart';
import '../../../gps/domain/gps_state.dart';
import '../../../gps/presentation/bloc/gps_cubit.dart';
import '../../../tracking/domain/live_trip.dart';
import '../../../tracking/presentation/bloc/tracking_bloc.dart';
import '../../domain/stop_proximity.dart';
import '../bloc/operations_cubit.dart';
import 'skip_stop_sheet.dart';

/// The driver's controls during a running trip.
///
/// The same widget on R1 and over the map, deliberately. A driver must be able
/// to work the bus without watching the map — the map is for looking ahead, not
/// for operating — so the controls are identical in both places and nothing is
/// reachable only from one.
///
/// What is offered depends on where the bus is, and only ever on what the
/// server says: at a stop, the counters; approaching one, Arrived and Skip;
/// nothing left, Complete trip.
class TripControls extends StatelessWidget {
  const TripControls({required this.tripId, this.compact = false, super.key});

  final String tripId;

  /// Over the map, where vertical space belongs to the road.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<TrackingBloc, TrackingState>(
      builder: (context, tracking) {
        final trip = tracking.trip;

        return BlocBuilder<OperationsCubit, OperationsState>(
          builder: (context, ops) {
            final atStop = trip?.currentStop;
            final next = trip?.nextStop;

            final body = Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (ops.refusal case final refusal?) ...[
                  _Refusal(refusal),
                  const SizedBox(height: Spacing.sm),
                ],

                // The count is always on screen during a run. Which actions go
                // with it depends on where the bus is, but how many people are
                // aboard never stops being the driver's business.
                _Occupancy(ops: ops, atStop: atStop, compact: compact),

                if (trip != null) ...[
                  const SizedBox(height: Spacing.md),
                  if (atStop == null && next != null)
                    _ApproachingControls(stop: next, ops: ops, eta: tracking.eta)
                  else if (atStop == null && next == null)
                    _CompleteControl(ops: ops),
                ],
              ],
            );

            // Over the map the controls need a ground of their own. Map tiles
            // are light whatever the app's theme is, and body text drawn
            // straight onto them disappears.
            if (!compact) return body;

            return Material(
              elevation: 6,
              borderRadius: BorderRadius.circular(Radii.md),
              color: Theme.of(context).colorScheme.surface,
              child: Padding(
                padding: const EdgeInsets.all(Spacing.md),
                child: body,
              ),
            );
          },
        );
      },
    );
  }
}

/// The count, and — when the bus is standing at a stop — the buttons that
/// change it.
class _Occupancy extends StatelessWidget {
  const _Occupancy({
    required this.ops,
    required this.atStop,
    required this.compact,
  });

  final OperationsState ops;
  final LiveStop? atStop;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final colors = context.ctms;
    final cubit = context.read<OperationsCubit>();

    final stop = atStop;
    final size = compact ? Sizes.buttonProminent + 8 : Sizes.counterButton;

    // Counting only happens at a stop. Between stops the figure stands alone —
    // offering the buttons there invites a tap for someone who is not present.
    if (stop == null) {
      return Align(
        alignment: Alignment.centerLeft,
        child: BigNumberDisplay(
          value: ops.occupied ?? 0,
          total: ops.capacity,
          label: strings.opsOnBoard,
          pending: ops.pending,
          pendingLabel: strings.opsNotYetSynced(ops.pending),
          tone: ops.isFull ? colors.caution : null,
        ),
      );
    }

    // OFF on the left, the count in the middle, ON on the right — the order the
    // door works in, so a driver's thumb goes to the same side every time.
    return Row(
      children: [
        CounterButton(
          icon: AppIcon.alight,
          label: strings.opsAlight,
          tone: colors.caution,
          size: size,
          onPressed: (ops.occupied ?? 0) <= 0
              ? null
              : () => cubit.alight(routeStopId: stop.stopId),
        ),
        Expanded(
          child: Center(
            child: BigNumberDisplay(
              value: ops.occupied ?? 0,
              total: ops.capacity,
              label: strings.opsOnBoard,
              pending: ops.pending,
              pendingLabel: strings.opsNotYetSynced(ops.pending),
              tone: ops.isFull ? colors.caution : null,
              centred: true,
            ),
          ),
        ),
        CounterButton(
          icon: AppIcon.board,
          label: strings.opsBoard,
          tone: colors.positive,
          size: size,
          // The server owns the capacity rule. This only stops the obvious case
          // locally so a driver is not made to wait for a refusal they could
          // see coming.
          onPressed:
              ops.isFull ? null : () => cubit.board(routeStopId: stop.stopId),
        ),
      ],
    );
  }
}

/// The bus is on its way to a stop: arrive, or say why not.
class _ApproachingControls extends StatelessWidget {
  const _ApproachingControls({
    required this.stop,
    required this.ops,
    required this.eta,
  });

  final LiveStop stop;
  final OperationsState ops;

  /// The server's estimate for this stop, which carries the road distance.
  final Eta? eta;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return BlocBuilder<GpsCubit, GpsState>(
      builder: (context, gps) {
        // Two different questions, deliberately answered by two different
        // things. `near` is straight-line and decides only whether the driver
        // is standing at the stop — the right measure for a 100 m radius, and
        // the wrong one for a journey. The road distance comes from the
        // server, which computed it from the same route the ETA came from.
        final near = _proximity(context, gps.lastFix);
        final atStop = near != null && near.withinRadius;
        final road = eta?.readableDistance;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            // At the stop: the device's own fix said so, and that is why the
            // Arrived button is lit. Short of it: the road still to drive,
            // which only the server can know. Nothing is shown when the
            // server has not said — a straight line across the fields is not
            // a lesser version of a road distance, it is a different number.
            if (atStop || road != null)
              Padding(
                padding: const EdgeInsets.only(bottom: Spacing.sm),
                child: Text(
                  atStop
                      ? strings.opsAtStopNow(stop.name ?? '')
                      : strings.opsDistanceToStop(road!, stop.name ?? ''),
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: atStop
                        ? colors.positive
                        : theme.colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            Row(
              children: [
                Expanded(
                  flex: 2,
                  child: SizedBox(
                    height: Sizes.buttonProminent,
                    child: FilledButton.icon(
                      key: const Key('ops-arrived'),
                      onPressed: ops.busy ? null : () => _arrive(context),
                      style: atStop
                          ? FilledButton.styleFrom(
                              backgroundColor: colors.positive,
                              foregroundColor: colors.onPositive,
                            )
                          : null,
                      icon: ops.busy
                          ? const SizedBox.square(
                              dimension: IconSize.sm,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const AppIconView(AppIcon.stop, size: IconSize.md),
                      label: Text(
                        // Names the stop once the bus is at it, so a driver
                        // pressing this in the wrong place can see that it is
                        // the wrong place.
                        atStop
                            ? strings.opsArrivedAt(stop.name ?? '')
                            : strings.opsArrived,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: Spacing.sm),
                Expanded(
                  child: SizedBox(
                    height: Sizes.buttonProminent,
                    child: OutlinedButton(
                      key: const Key('ops-skip'),
                      onPressed: ops.busy ? null : () => _skip(context),
                      child: Text(strings.opsSkip),
                    ),
                  ),
                ),
              ],
            ),
          ],
        );
      },
    );
  }

  /// How far the bus is from this stop, when both positions are known.
  ///
  /// Null when the device has no fix or the stop geometry has not loaded. The
  /// plain Arrived button still stands in that case: BR-306 exists so a driver
  /// whose GPS is dead can record an arrival by hand, and a geofence the phone
  /// cannot compute must never be the thing that strands them.
  StopProximity? _proximity(BuildContext context, PositionFix? fix) {
    if (fix == null) return null;

    for (final candidate in context.read<TrackingBloc>().state.stops) {
      if (candidate.id != stop.stopId) continue;

      return StopProximity.between(
        fromLatitude: fix.latitude,
        fromLongitude: fix.longitude,
        toLatitude: candidate.latitude,
        toLongitude: candidate.longitude,
      );
    }

    return null;
  }

  Future<void> _arrive(BuildContext context) async {
    await context.read<OperationsCubit>().arrive(stop.stopId);
  }

  /// S4. The sheet owns its own field and returns the reason, so nothing here
  /// manages a controller that outlives the pop.
  Future<void> _skip(BuildContext context) async {
    final cubit = context.read<OperationsCubit>();

    final reason = await SkipStopSheet.show(context, stopName: stop.name ?? '');

    if (reason != null) await cubit.skip(stop.stopId, reason);
  }
}

/// Every stop is done. The only thing left is to close the trip.
class _CompleteControl extends StatelessWidget {
  const _CompleteControl({required this.ops});

  final OperationsState ops;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return SizedBox(
      height: Sizes.buttonProminent,
      child: FilledButton.icon(
        key: const Key('ops-complete'),
        onPressed: ops.busy ? null : () => _complete(context),
        icon: const AppIconView(AppIcon.tripEnd, size: IconSize.md),
        label: Text(
          strings.opsComplete,
          style: Theme.of(
            context,
          ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
        ),
      ),
    );
  }

  Future<void> _complete(BuildContext context) async {
    final strings = AppStrings.of(context);
    final cubit = context.read<OperationsCubit>();

    final confirmed = await ConfirmSheet.show(
      context,
      title: strings.opsCompleteTitle,
      body: strings.opsCompleteBody,
      confirmLabel: strings.opsComplete,
      cancelLabel: strings.cancel,
    );

    if (confirmed) await cubit.complete();
  }
}

/// The server said no. Its words, not a paraphrase — a full bus and a trip that
/// has already been closed are different problems with different answers.
class _Refusal extends StatelessWidget {
  const _Refusal(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Container(
      padding: const EdgeInsets.all(Spacing.sm),
      decoration: BoxDecoration(
        color: colors.critical.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(Radii.sm),
      ),
      child: Row(
        children: [
          AppIconView(AppIcon.error, size: IconSize.sm, color: colors.critical),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Text(
              message,
              style: Theme.of(
                context,
              ).textTheme.bodyMedium?.copyWith(color: colors.critical),
            ),
          ),
          IconButton(
            onPressed: () => context.read<OperationsCubit>().clearRefusal(),
            icon: const AppIconView(AppIcon.close, size: IconSize.sm),
            tooltip: AppStrings.of(context).cancel,
          ),
        ],
      ),
    );
  }
}
