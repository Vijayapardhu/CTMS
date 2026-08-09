import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/reason_list.dart';
import '../../../core/widgets/skeleton_loader.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/trip.dart';
import '../domain/trip_state.dart';
import 'bloc/trip_bloc.dart';
import 'widgets/trip_card.dart';

/// R1 — the trip root.
///
/// One destination, eight states. Slice 3 reads them all; the controls that
/// change a trip — start, inspection, boarding, ending — belong to the slices
/// that own those actions and are deliberately absent here.
class TripScreen extends StatefulWidget {
  const TripScreen({super.key});

  @override
  State<TripScreen> createState() => _TripScreenState();
}

class _TripScreenState extends State<TripScreen> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    // The bloc is app-scoped, so it may already hold today's trip from an
    // earlier visit to this tab. Only ask when there is nothing yet.
    final bloc = context.read<TripBloc>();
    if (bloc.state is TripLoading) bloc.add(const TripRequested());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
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
      body: BlocBuilder<TripBloc, TripState>(
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
      TripWaiting(:final value, :final opensAt) =>
        _Waiting(state: state, trip: value, opensAt: opensAt),
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
        // "Start inspection" belongs to the inspection slice. Rendering it
        // here without the flow behind it would be a button that lies.
      ],
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

  final TripState state;
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
        // START TRIP is the start slice's control, not this one's.
      ],
    );
  }
}

/// `waiting` — cleared, but outside the start window.
class _Waiting extends StatelessWidget {
  const _Waiting({
    required this.state,
    required this.trip,
    required this.opensAt,
  });

  final TripState state;
  final Trip trip;
  final DateTime opensAt;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),
        Text(
          strings.tripStartWindowOpens(
            '${opensAt.hour.toString().padLeft(2, '0')}:'
            '${opensAt.minute.toString().padLeft(2, '0')}',
          ),
          style: theme.textTheme.titleMedium,
        ),
        const SizedBox(height: Spacing.md),
        _TripFacts(trip),
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
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final capacity = trip.bus?.seatingCapacity;
    final occupied = trip.occupiedSeatCount;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TripCard(state: state),
        const SizedBox(height: Spacing.lg),
        if (occupied != null && capacity != null)
          Text(
            strings.tripOnBoard(occupied, capacity),
            style: theme.textTheme.headlineSmall,
          ),
        const SizedBox(height: Spacing.md),
        _TripFacts(trip),
      ],
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
