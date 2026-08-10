import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/consequence_panel.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/skeleton_loader.dart';
import '../../../l10n/app_localizations.dart';
import '../../gps/presentation/bloc/gps_cubit.dart';
import '../../trip/presentation/bloc/trip_bloc.dart';
import '../domain/incident.dart';
import 'bloc/incident_cubit.dart';
import 'widgets/incident_evidence.dart';

/// P18/P19 — reporting an operational problem.
///
/// The form is built from `GET /incidents/types`: what to choose from, which
/// choices need a description, and which need a photograph all come from the
/// server. Nothing about the list is decided here.
class IncidentScreen extends StatelessWidget {
  const IncidentScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(strings.incidentTitle)),
      body: BlocBuilder<IncidentCubit, IncidentState>(
        builder: (context, state) {
          if (state.outcome != null) return _Reported(state.outcome!);
          if (state.queued) return const _Queued();

          if (state.loading) {
            return const Padding(
              padding: EdgeInsets.all(Spacing.md),
              child: SkeletonLoader(shape: SkeletonShape.list, count: 6),
            );
          }

          if (state.loadFailed) {
            return Padding(
              padding: const EdgeInsets.all(Spacing.lg),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  EmptyState(
                    icon: AppIcon.warning,
                    title: strings.incidentTypesUnavailableTitle,
                    body: strings.incidentTypesUnavailableBody,
                  ),
                  const SizedBox(height: Spacing.lg),
                  SizedBox(
                    height: Sizes.buttonHeight,
                    child: FilledButton(
                      onPressed: () => context.read<IncidentCubit>().load(),
                      child: Text(strings.retry),
                    ),
                  ),
                ],
              ),
            );
          }

          return _Form(state);
        },
      ),
    );
  }
}

class _Form extends StatelessWidget {
  const _Form(this.state);

  final IncidentState state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final cubit = context.read<IncidentCubit>();
    final selected = state.selected;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(Spacing.md),
            children: [
              if (state.refusal case final refusal?) ...[
                _Refusal(refusal),
                const SizedBox(height: Spacing.md),
              ],
              Text(strings.incidentWhatHappened,
                  style: theme.textTheme.titleMedium),
              const SizedBox(height: Spacing.sm),

              // Grouped the way the server groups them: life safety, then
              // operational, then service.
              for (final entry in state.byClass.entries) ...[
                Padding(
                  padding: const EdgeInsets.only(
                    top: Spacing.md,
                    bottom: Spacing.xs,
                  ),
                  child: Text(
                    entry.key,
                    style: theme.textTheme.labelLarge
                        ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ),
                for (final type in entry.value)
                  _TypeTile(
                    type: type,
                    selected: selected?.type == type.type,
                    onTap: () => cubit.select(type),
                  ),
              ],

              if (selected != null) ...[
                const Divider(height: Spacing.xxl),
                if (selected.requiresDescription) ...[
                  TextField(
                    minLines: 3,
                    maxLines: 6,
                    maxLength: 2000,
                    textCapitalization: TextCapitalization.sentences,
                    onChanged: cubit.describe,
                    decoration: InputDecoration(
                      labelText: strings.incidentDescription,
                      helperText: strings.incidentDescriptionHint,
                    ),
                  ),
                  const SizedBox(height: Spacing.md),
                ],

                // The server refuses an operational fault with no photograph,
                // so the requirement is stated before the driver tries.
                if (selected.requiresPhoto) ...[
                  IncidentEvidence(
                    label: selected.label,
                    attachedId: state.evidenceId,
                    onAttached: cubit.attach,
                  ),
                  const SizedBox(height: Spacing.md),
                ],

                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  value: state.vehicleCanContinue ?? true,
                  onChanged: cubit.setVehicleCanContinue,
                  title: Text(strings.incidentCanContinue),
                  subtitle: Text(strings.incidentCanContinueHint),
                ),
              ],
            ],
          ),
        ),
        SafeArea(
          minimum: const EdgeInsets.all(Spacing.md),
          child: SizedBox(
            height: Sizes.buttonProminent,
            child: FilledButton(
              onPressed: state.isComplete && !state.submitting
                  ? () => _submit(context)
                  : null,
              child: state.submitting
                  ? const SizedBox.square(
                      dimension: IconSize.sm,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(
                      strings.incidentSubmit,
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.bold),
                    ),
            ),
          ),
        ),
      ],
    );
  }

  void _submit(BuildContext context) {
    final trip = context.read<TripBloc>().state.trip;
    final fix = context.read<GpsCubit>().state.lastFix;

    context.read<IncidentCubit>().submit(
          tripId: trip?.id,
          latitude: fix?.latitude,
          longitude: fix?.longitude,
        );
  }
}

class _TypeTile extends StatelessWidget {
  const _TypeTile({
    required this.type,
    required this.selected,
    required this.onTap,
  });

  final IncidentType type;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;
    final critical = type.isLifeSafety;

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.sm),
      child: Material(
        color: selected
            ? theme.colorScheme.primaryContainer
            : theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(Radii.md),
        child: InkWell(
          borderRadius: BorderRadius.circular(Radii.md),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(Spacing.md),
            child: Row(
              children: [
                AppIconView(
                  critical ? AppIcon.error : AppIcon.breakdown,
                  size: IconSize.md,
                  color: critical ? colors.critical : colors.caution,
                ),
                const SizedBox(width: Spacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(type.label, style: theme.textTheme.titleMedium),
                      // Says what this choice will cost before it is made.
                      if (type.requiresPhoto)
                        Text(
                          strings.incidentNeedsPhoto,
                          style: theme.textTheme.bodySmall
                              ?.copyWith(color: colors.caution),
                        ),
                    ],
                  ),
                ),
                if (selected)
                  AppIconView(AppIcon.pass,
                      size: IconSize.md, color: colors.positive),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The server took it, and said what it did.
class _Reported extends StatelessWidget {
  const _Reported(this.outcome);

  final IncidentOutcome outcome;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.all(Spacing.lg),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AppIconView(AppIcon.success, size: IconSize.sos, color: colors.positive),
          const SizedBox(height: Spacing.lg),
          Text(
            outcome.message ?? strings.incidentReported,
            textAlign: TextAlign.center,
            style: theme.textTheme.headlineSmall,
          ),

          // The consequence, in the server's terms. The app never decides that
          // a bus is off the road.
          if (outcome.groundedVehicle) ...[
            const SizedBox(height: Spacing.xl),
            ConsequencePanel(
              severity: ConsequenceSeverity.danger,
              title: strings.incidentBusOutOfService,
              body: strings.incidentMaintenanceOpened,
            ),
          ],
          const SizedBox(height: Spacing.xl),
          SizedBox(
            height: Sizes.buttonHeight,
            child: FilledButton(
              onPressed: () => Navigator.of(context).maybePop(),
              child: Text(strings.incidentBackToTrip),
            ),
          ),
        ],
      ),
    );
  }
}

/// Held on the phone.
class _Queued extends StatelessWidget {
  const _Queued();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.all(Spacing.lg),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AppIconView(AppIcon.sync, size: IconSize.sos, color: colors.caution),
          const SizedBox(height: Spacing.lg),
          Text(
            strings.incidentQueuedTitle,
            textAlign: TextAlign.center,
            style: theme.textTheme.headlineSmall?.copyWith(color: colors.caution),
          ),
          const SizedBox(height: Spacing.sm),
          Text(
            strings.incidentQueuedBody,
            textAlign: TextAlign.center,
            style: theme.textTheme.bodyLarge,
          ),
          const SizedBox(height: Spacing.xl),
          SizedBox(
            height: Sizes.buttonHeight,
            child: FilledButton(
              onPressed: () => Navigator.of(context).maybePop(),
              child: Text(strings.incidentBackToTrip),
            ),
          ),
        ],
      ),
    );
  }
}

/// The server's refusal, verbatim.
class _Refusal extends StatelessWidget {
  const _Refusal(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Container(
      padding: const EdgeInsets.all(Spacing.md),
      decoration: BoxDecoration(
        color: colors.critical.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(Radii.sm),
      ),
      child: Row(
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
      ),
    );
  }
}
