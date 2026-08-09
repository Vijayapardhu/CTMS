import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';

import '../../../app/router/routes.dart';

import '../../../core/connectivity/connectivity_cubit.dart';
import '../../../core/connectivity/connectivity_service.dart';
import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/confirm_sheet.dart';
import '../../../core/widgets/consequence_panel.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/reason_list.dart';
import '../../../core/widgets/skeleton_loader.dart';
import '../../gps/domain/gps_state.dart';
import '../../gps/presentation/bloc/gps_cubit.dart';
import '../../gps/presentation/widgets/gps_status_pill.dart';
import '../../operations/presentation/bloc/operations_cubit.dart';
import '../../operations/presentation/widgets/trip_controls.dart';
import '../../tracking/presentation/bloc/tracking_bloc.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/trip.dart';
import '../domain/trip_state.dart';
import 'bloc/trip_bloc.dart';
import 'widgets/trip_card.dart';

/// The START TRIP control, in whichever state renders it.
const startTripKey = Key('trip-start');

/// R1 — the trip root.
///
/// One destination, eight states. Reading them is slice 3; starting the trip is
/// slice 6. Boarding and ending belong to the slices that own those actions and
/// are deliberately absent here.
class TripScreen extends StatefulWidget {
  const TripScreen({super.key});

  @override
  State<TripScreen> createState() => _TripScreenState();
}

class _TripScreenState extends State<TripScreen> with WidgetsBindingObserver {
  /// Held from `initState`, because `dispose` may not look up an inherited
  /// widget and the stream still has to be stopped when the tree goes.
  late final GpsCubit _gps;
  late final TrackingBloc _tracking;
  late final OperationsCubit _operations;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _gps = context.read<GpsCubit>();
    _tracking = context.read<TrackingBloc>();
    _operations = context.read<OperationsCubit>();

    // The bloc is app-scoped, so it may already hold today's trip from an
    // earlier visit to this tab. Only ask when there is nothing yet.
    final bloc = context.read<TripBloc>();
    if (bloc.state is TripLoading) bloc.add(const TripRequested());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    // M3 outlives this *screen* — the shell's indexed stack keeps it mounted
    // while the driver reads the map, so a tab switch never reaches here. What
    // does reach here is the app going away or the session ending, and letting
    // the position stream run past either would keep the hardware awake for a
    // trip nobody is driving.
    // Guarded: on sign-out the graph is torn down before the tree is, so both
    // of these can already be closed by the time this runs. Stopping something
    // that has stopped is not worth an exception in a driver's face.
    if (!_gps.isClosed) _gps.stop();
    if (!_tracking.isClosed) _tracking.add(const TrackingStopped());
    super.dispose();
  }

  /// Keeps M3 in step with M1.
  ///
  /// The stream belongs to the trip, not to this screen — a driver on the map
  /// tab is still driving — so it is started and stopped from the state rather
  /// than from `initState`/`dispose`.
  void _followTrip(TripState state) {
    if (state is TripRunning) {
      _gps.start(state.value.id);
      _operations.adopt(
        tripId: state.value.id,
        occupied: state.value.occupiedSeatCount,
        capacity: state.value.bus?.seatingCapacity,
      );

      // The map's poll is started from here rather than from the map itself:
      // a driver who never opens the Map tab still has a trip being tracked,
      // and the sheet on this screen reads the same live state.
      final routeId = state.value.route?.id;
      if (routeId != null) {
        _tracking.add(TrackingStarted(
          tripId: state.value.id,
          routeId: routeId,
        ));
      }
    } else {
      _gps.stop();
      _tracking.add(const TrackingStopped());
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // R1: auto-refresh on resume. A driver who put the phone in a pocket at
    // 07:40 and takes it out at 08:05 must not act on the older answer.
    if (state == AppLifecycleState.resumed && mounted) {
      context.read<TripBloc>().add(const TripRefreshed());
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(strings.tabTrip)),
      body: BlocConsumer<TripBloc, TripState>(
        listener: (context, state) => _followTrip(state),
        builder: (context, state) {
          return RefreshIndicator(
            // Pull-to-refresh is always available, per R1. It never replaces
            // the content with a skeleton — whatever is on screen stays there
            // while the request runs.
            onRefresh: () async {
              final bloc = context.read<TripBloc>();
              bloc.add(const TripRefreshed());
              await bloc.stream.first;
            },
            child: ListView(
              // Always scrollable, or a short screen cannot be pulled to
              // refresh at all.
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(Spacing.md),
              children: [
                if (state.stale) const _StaleNotice(),
                _body(context, state),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _body(BuildContext context, TripState state) {
    return switch (state) {
      TripLoading() => const SkeletonLoader(shape: SkeletonShape.card),
      TripNone() => const _NoTrip(),
      TripUnavailable(:final reason) => _Unavailable(reason.message),
      TripBlocked(:final clearance) => _Blocked(state: state, clearance: clearance),
      TripReady(:final value, :final clearance) =>
        _Ready(state: state, trip: value, clearance: clearance),
      TripWaiting() => _Waiting(state: state),
      TripRunning(:final value) => _Running(state: state, trip: value),
      TripClosed(:final value) => _Closed(state: state, trip: value),
    };
  }
}

/// The trip on screen is held over from an earlier read.
///
/// Never presented as current — a driver acting on a stale assignment is the
/// failure this line exists to prevent.
class _StaleNotice extends StatelessWidget {
  const _StaleNotice();

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.md),
      child: Row(
        children: [
          AppIconView(AppIcon.warning, size: IconSize.sm, color: colors.caution),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Text(
              AppStrings.of(context).tripStale,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: colors.caution),
            ),
          ),
        ],
      ),
    );
  }
}

/// `none` — the server answered, and the answer was no trip.
class _NoTrip extends StatelessWidget {
  const _NoTrip();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Padding(
      padding: const EdgeInsets.only(top: Spacing.xxl),
      child: EmptyState(
        icon: AppIcon.schedule,
        title: strings.tripNoneTitle,
        body: strings.tripNoneBody,
        // R1 calls for a "Call the office" action here. No endpoint in the
        // frozen contract returns an office number and no configuration holds
        // one, so the action is absent rather than wired to an invented
        // number. Recorded as a specification gap in Phase 9.
      ),
    );
  }
}

/// `unavailable` — the read failed and nothing is cached.
class _Unavailable extends StatelessWidget {
  const _Unavailable(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.only(top: Spacing.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(Spacing.md),
            decoration: BoxDecoration(
              color: colors.critical.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(Radii.md),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    AppIconView(AppIcon.error, size: IconSize.sm, color: colors.critical),
                    const SizedBox(width: Spacing.sm),
                    Expanded(
                      child: Text(
                        strings.tripUnavailableTitle,
                        style: theme.textTheme.titleMedium
                            ?.copyWith(color: colors.critical),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: Spacing.sm),
                // The distinction that matters: this is not "no trip".
                Text(strings.tripUnavailableBody, style: theme.textTheme.bodyMedium),
                const SizedBox(height: Spacing.xs),
                Text(
                  message,
                  style: theme.textTheme.bodySmall
                      ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
          const SizedBox(height: Spacing.lg),
          SizedBox(
            height: Sizes.buttonHeight,
            child: FilledButton(
              onPressed: () => context.read<TripBloc>().add(const TripRefreshed()),
              child: Text(strings.tripRetry),
            ),
          ),
        ],
      ),
    );
  }
}

/// `blocked` — a trip exists, the bus is not cleared.
class _Blocked extends StatelessWidget {
  const _Blocked({required this.state, required this.clearance});

  final TripState state;
  final ServiceReadiness clearance;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),
        ReasonList(
          actionable: clearance.actionable,
          blocking: clearance.blocking,
          actionableHeading: strings.tripReasonsActionable,
          blockingHeading: strings.tripReasonsBlocking,
        ),
        if (clearance.checkedAt != null) ...[
          const SizedBox(height: Spacing.sm),
          _CheckedAt(clearance.checkedAt!),
        ],
        // Offered only when something is actionable. A driver shown "Start
        // inspection" against an expired insurance certificate does the work
        // and is still blocked, with no idea why.
        if (clearance.actionable.isNotEmpty) ...[
          const SizedBox(height: Spacing.xl),
          _StartInspection(trip: state.trip!),
        ],
      ],
    );
  }
}

/// The one way out of `blocked` that belongs to the driver.
class _StartInspection extends StatelessWidget {
  const _StartInspection({required this.trip});

  final Trip trip;

  @override
  Widget build(BuildContext context) {
    final busId = trip.busId;
    if (busId == null) return const SizedBox.shrink();

    return SizedBox(
      height: Sizes.buttonProminent,
      child: FilledButton(
        onPressed: () => context.goNamed(
          Routes.inspection,
          pathParameters: {'busId': busId},
          queryParameters: {
            if (trip.bus?.currentOdometer != null)
              'min': '${trip.bus!.currentOdometer}',
            'bus': trip.bus!.registrationNumber,
          },
        ),
        child: Text(AppStrings.of(context).tripStartInspection),
      ),
    );
  }
}

/// `ready` — cleared to start.
class _Ready extends StatelessWidget {
  const _Ready({
    required this.state,
    required this.trip,
    required this.clearance,
  });

  final TripReady state;
  final Trip trip;
  final ServiceReadiness clearance;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),
        _TripFacts(trip),
        if (clearance.checkedAt != null) ...[
          const SizedBox(height: Spacing.sm),
          _CheckedAt(clearance.checkedAt!),
        ],
        const SizedBox(height: Spacing.xl),
        _StartTrip(trip: trip, starting: state.starting),
        if (state.refusal case final refusal?) ...[
          const SizedBox(height: Spacing.md),
          _StartRefusal(refusal),
        ],
      ],
    );
  }
}

/// The 64dp control this screen exists to offer.
///
/// Disabled with no connection, because starting is not a thing that can be
/// queued: a trip is running only when the server says so, and a driver who
/// believed otherwise would pull out with nothing behind them.
class _StartTrip extends StatelessWidget {
  const _StartTrip({required this.trip, required this.starting});

  final Trip trip;
  final bool starting;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final offline =
        context.watch<ConnectivityCubit>().state == Reachability.offline;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          height: Sizes.buttonProminent,
          child: FilledButton.icon(
            // Keyed: the start control is one affordance across two states,
            // and `FilledButton.icon` builds a private subclass that a type
            // finder cannot see.
            key: startTripKey,
            onPressed:
                offline || starting ? null : () => _confirm(context, strings),
            icon: starting
                ? const SizedBox.square(
                    dimension: IconSize.sm,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const AppIconView(AppIcon.tripStart, size: IconSize.md),
            label: Text(
              strings.tripStart,
              style: theme.textTheme.titleLarge
                  ?.copyWith(fontWeight: FontWeight.bold),
            ),
          ),
        ),
        if (offline) ...[
          const SizedBox(height: Spacing.sm),
          Text(
            strings.tripStartOffline,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            textAlign: TextAlign.center,
          ),
        ],
      ],
    );
  }

  /// S2 — one tap to open, one to agree. The sheet states what is about to
  /// happen and asks nothing else.
  Future<void> _confirm(BuildContext context, AppStrings strings) async {
    final bloc = context.read<TripBloc>();

    final confirmed = await ConfirmSheet.show(
      context,
      title: strings.tripStartConfirmTitle,
      body: strings.tripStartConfirmBody,
      confirmLabel: strings.tripStartConfirm,
      cancelLabel: strings.tripStartCancel,
      child: _StartFacts(trip),
    );

    if (confirmed) bloc.add(const TripStartRequested());
  }
}

/// What the driver is agreeing to, inside the sheet.
class _StartFacts extends StatelessWidget {
  const _StartFacts(this.trip);

  final Trip trip;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (trip.bus?.registrationNumber case final bus?)
          Text(bus, style: theme.textTheme.titleLarge),
        if (trip.route?.name case final route?)
          Text(route, style: theme.textTheme.bodyLarge),
        if (trip.scheduledDeparture case final departs?)
          Text(strings.tripDeparture(departs), style: theme.textTheme.bodyLarge),
      ],
    );
  }
}

/// A refusal that did not change what the trip is — a rate limit, a server
/// fault, a dead connection. The server's own wording, never a paraphrase.
class _StartRefusal extends StatelessWidget {
  const _StartRefusal(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        AppIconView(AppIcon.error, size: IconSize.sm, color: colors.critical),
        const SizedBox(width: Spacing.sm),
        Expanded(
          child: Text(
            message,
            style: Theme.of(context)
                .textTheme
                .bodyMedium
                ?.copyWith(color: colors.critical),
          ),
        ),
      ],
    );
  }
}

/// `waiting` — cleared, but outside the start window.
class _Waiting extends StatelessWidget {
  const _Waiting({required this.state});

  final TripWaiting state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),
        // The server's sentence, which names the time. Nothing here recomputes
        // it: the check-in window is server configuration and is published
        // nowhere in the contract.
        Text(state.message, style: theme.textTheme.titleMedium),
        const SizedBox(height: Spacing.sm),
        Text(
          strings.tripStartWindowWaiting,
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: Spacing.md),
        _TripFacts(state.value),
        const SizedBox(height: Spacing.xl),
        // Deliberately dead. The trip is early; the bloc puts a live button
        // back on its own, so there is nothing here for the driver to do.
        SizedBox(
          height: Sizes.buttonProminent,
          child: FilledButton(
            key: startTripKey,
            onPressed: null,
            child: Text(strings.tripStart),
          ),
        ),
      ],
    );
  }
}

/// `running` — in progress.
///
/// Slice 3 reads it. The live position, the GPS pill and the boarding counters
/// arrive with the slices that own them; what is here is what `/trips` itself
/// returns.
class _Running extends StatelessWidget {
  const _Running({required this.state, required this.trip});

  final TripState state;
  final Trip trip;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // M3, above everything: whether the office can see this bus is the
        // first thing a driver should be able to check.
        BlocBuilder<GpsCubit, GpsState>(
          builder: (context, gps) => Align(
            alignment: Alignment.centerLeft,
            child: GpsStatusPill(state: gps),
          ),
        ),
        const SizedBox(height: Spacing.md),
        BlocBuilder<GpsCubit, GpsState>(
          builder: (context, gps) =>
              gps is GpsDenied ? const _GpsUnavailable() : const SizedBox.shrink(),
        ),
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),

        // Where the bus is in the run, from the live poll rather than from the
        // trip row, which does not change as stops are reached.
        const _StopProgress(),
        const SizedBox(height: Spacing.lg),
        _TripFacts(trip),
        const SizedBox(height: Spacing.xl),

        // The same controls the map carries. A driver must never have to open
        // the map to operate the bus.
        TripControls(tripId: trip.id),
      ],
    );
  }
}

/// Which stop the bus is at or heading for, and when it gets there.
class _StopProgress extends StatelessWidget {
  const _StopProgress();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return BlocBuilder<TrackingBloc, TrackingState>(
      builder: (context, state) {
        final trip = state.trip;
        if (trip == null) return const SizedBox.shrink();

        final at = trip.currentStop;
        final next = trip.nextStop;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              at != null ? strings.mapAtStop(at.name ?? '') : strings.mapNextStop,
              style: theme.textTheme.labelLarge
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
            Text(
              (at ?? next)?.name ?? strings.mapNoMoreStops,
              style: theme.textTheme.headlineSmall,
            ),
            if (state.eta?.isUsable ?? false)
              Text(
                strings.mapEtaMinutes(state.eta!.minutes!),
                style: theme.textTheme.titleMedium,
              ),
          ],
        );
      },
    );
  }
}

/// `closed` — finished or cancelled.
class _Closed extends StatelessWidget {
  const _Closed({required this.state, required this.trip});

  final TripState state;
  final Trip trip;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),
        // The server's own wording. A driver whose trip vanished under them
        // deserves the reason, not a paraphrase.
        if (trip.cancellationReason != null)
          Text(
            strings.tripCancelledBecause(trip.cancellationReason!),
            style: theme.textTheme.bodyMedium,
          ),
        if (trip.autoClosed) ...[
          const SizedBox(height: Spacing.sm),
          Text(strings.tripAutoClosed, style: theme.textTheme.bodyMedium),
        ],
        const SizedBox(height: Spacing.md),
        _TripFacts(trip),
      ],
    );
  }
}

/// The figures the trip payload itself carries.
class _TripFacts extends StatelessWidget {
  const _TripFacts(this.trip);

  final Trip trip;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final stops = trip.route?.stopCount;
    final booked = trip.bookedSeatCount;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (stops != null)
          Text(strings.tripStopCount(stops), style: theme.textTheme.bodyMedium),
        if (booked != null)
          Text(strings.tripExpected(booked), style: theme.textTheme.bodyMedium),
      ],
    );
  }
}

/// When the clearance answer was obtained, so an old one cannot pass for fresh.
class _CheckedAt extends StatelessWidget {
  const _CheckedAt(this.at);

  final DateTime at;

  @override
  Widget build(BuildContext context) {
    final local = at.toLocal();

    return Text(
      AppStrings.of(context).tripReadinessCheckedAt(
        '${local.hour.toString().padLeft(2, '0')}:'
        '${local.minute.toString().padLeft(2, '0')}',
      ),
      style: Theme.of(context)
          .textTheme
          .bodySmall
          ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
    );
  }
}

/// E2 — location is off or refused, during a running trip.
///
/// Deliberately a panel and not a dialog or a route. M3 says never block and
/// never stop the trip: the bus is still running, the boardings still record,
/// and the only thing lost is the office's view of where it is. That is worth
/// stating plainly and worth offering a fix for, but not worth standing in
/// front of a driver who is about to pull away.
class _GpsUnavailable extends StatelessWidget {
  const _GpsUnavailable();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ConsequencePanel(
            severity: ConsequenceSeverity.warning,
            title: strings.gpsDeniedTitle,
            body: strings.gpsDeniedBody,
          ),
          const SizedBox(height: Spacing.sm),
          SizedBox(
            height: Sizes.buttonHeight,
            child: OutlinedButton(
              onPressed: Geolocator.openAppSettings,
              child: Text(strings.gpsOpenSettings),
            ),
          ),
        ],
      ),
    );
  }
}
