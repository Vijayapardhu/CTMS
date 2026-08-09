import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/design_system/ctms_colors.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../core/widgets/big_number_display.dart';
import '../../../../core/widgets/confirm_sheet.dart';
import '../../../../core/widgets/counter_button.dart';
import '../../../../l10n/app_localizations.dart';
import '../../../tracking/domain/live_trip.dart';
import '../../../tracking/presentation/bloc/tracking_bloc.dart';
import '../bloc/operations_cubit.dart';

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

            return Column(
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
                    _ApproachingControls(stop: next, ops: ops)
                  else if (atStop == null && next == null)
                    _CompleteControl(ops: ops),
                ],
              ],
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

    return Row(
      children: [
        Expanded(
          child: BigNumberDisplay(
            value: ops.occupied ?? 0,
            total: ops.capacity,
            label: strings.opsOnBoard,
            pending: ops.pending,
            pendingLabel: strings.opsNotYetSynced(ops.pending),
            tone: ops.isFull ? colors.caution : null,
          ),
        ),
        // Counting only happens at a stop. Offering the buttons between stops
        // would invite a tap for someone who is not there.
        if (stop != null) ...[
          CounterButton(
            icon: AppIcon.alight,
            label: strings.opsAlight,
            tone: colors.caution,
            size: compact ? Sizes.buttonProminent + 8 : Sizes.counterButton,
            onPressed: (ops.occupied ?? 0) <= 0
                ? null
                : () => cubit.alight(routeStopId: stop.stopId),
          ),
          const SizedBox(width: Spacing.sm),
          CounterButton(
            icon: AppIcon.board,
            label: strings.opsBoard,
            tone: colors.positive,
            size: compact ? Sizes.buttonProminent + 8 : Sizes.counterButton,
            // The server owns the capacity rule. This only stops the obvious
            // case locally so a driver is not made to wait for a refusal they
            // could see coming.
            onPressed: ops.isFull
                ? null
                : () => cubit.board(routeStopId: stop.stopId),
          ),
        ],
      ],
    );
  }
}

/// The bus is on its way to a stop: arrive, or say why not.
class _ApproachingControls extends StatelessWidget {
  const _ApproachingControls({required this.stop, required this.ops});

  final LiveStop stop;
  final OperationsState ops;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Row(
      children: [
        Expanded(
          flex: 2,
          child: SizedBox(
            height: Sizes.buttonProminent,
            child: FilledButton.icon(
              key: const Key('ops-arrived'),
              onPressed: ops.busy ? null : () => _arrive(context),
              icon: ops.busy
                  ? const SizedBox.square(
                      dimension: IconSize.sm,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const AppIconView(AppIcon.stop, size: IconSize.md),
              label: Text(
                strings.opsArrived,
                style: Theme.of(
                  context,
                ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold),
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
    );
  }

  Future<void> _arrive(BuildContext context) async {
    await context.read<OperationsCubit>().arrive(stop.stopId);
  }

  /// S4 — skipping needs a reason, and the students waiting at that stop are
  /// told it. Five characters is the server's floor; the sheet says so rather
  /// than letting the driver find out by being refused.
  Future<void> _skip(BuildContext context) async {
    final strings = AppStrings.of(context);
    final cubit = context.read<OperationsCubit>();
    final controller = TextEditingController();

    final confirmed = await ConfirmSheet.show(
      context,
      title: strings.opsSkipTitle(stop.name ?? ''),
      body: strings.opsSkipBody,
      confirmLabel: strings.opsSkipConfirm,
      cancelLabel: strings.cancel,
      danger: true,
      child: TextField(
        controller: controller,
        autofocus: true,
        maxLength: 500,
        minLines: 2,
        maxLines: 4,
        textCapitalization: TextCapitalization.sentences,
        decoration: InputDecoration(
          labelText: strings.opsSkipReason,
          helperText: strings.opsSkipReasonHint,
        ),
      ),
    );

    final reason = controller.text.trim();
    controller.dispose();

    if (confirmed && reason.length >= 5) {
      await cubit.skip(stop.stopId, reason);
    }
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
