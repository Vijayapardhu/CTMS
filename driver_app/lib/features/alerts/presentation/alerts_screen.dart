import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/consequence_panel.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/skeleton_loader.dart';
import '../../../core/widgets/status_chip.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/alert.dart';
import 'bloc/alerts_cubit.dart';

/// R3 — the alerts tab.
///
/// The titles and bodies are the office's own words, carrying the bus
/// registration and the urgency already. Nothing here rewrites them.
class AlertsScreen extends StatefulWidget {
  const AlertsScreen({super.key});

  @override
  State<AlertsScreen> createState() => _AlertsScreenState();
}

class _AlertsScreenState extends State<AlertsScreen> {
  @override
  void initState() {
    super.initState();
    context.read<AlertsCubit>().load();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      appBar: AppBar(
        title: Text(strings.tabAlerts),
        actions: [
          BlocBuilder<AlertsCubit, AlertsState>(
            builder: (context, state) => state.unread == 0
                ? const SizedBox.shrink()
                : TextButton(
                    onPressed: () => context.read<AlertsCubit>().markAllRead(),
                    child: Text(strings.alertsMarkAllRead),
                  ),
          ),
        ],
      ),
      body: BlocBuilder<AlertsCubit, AlertsState>(
        builder: (context, state) {
          if (state.loading) {
            return const Padding(
              padding: EdgeInsets.all(Spacing.md),
              child: SkeletonLoader(shape: SkeletonShape.list, count: 5),
            );
          }

          // Nothing held and nothing arrived. "Showing the last alerts
          // received" would be false — there are none — and the calm empty
          // state would be worse still, because it says the office has sent
          // nothing when in truth the app could not ask.
          if (state.alerts.isEmpty && state.failure != null) {
            return _CouldNotRead(state.failure!);
          }

          return RefreshIndicator(
            onRefresh: () => context.read<AlertsCubit>().load(),
            child: state.isEmpty
                ? ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    children: [
                      const SizedBox(height: Spacing.xxl),
                      EmptyState(
                        icon: AppIcon.alerts,
                        title: strings.alertsEmptyTitle,
                        body: strings.alertsEmptyBody,
                      ),
                    ],
                  )
                : ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(Spacing.md),
                    children: [
                      if (state.failure != null) ...[
                        _StaleNotice(state.failure!.message),
                        const SizedBox(height: Spacing.md),
                      ],
                      for (final alert in state.alerts)
                        _AlertTile(
                          alert: alert,
                          onTap: () =>
                              context.read<AlertsCubit>().markRead(alert),
                        ),
                    ],
                  ),
          );
        },
      ),
    );
  }
}

class _AlertTile extends StatelessWidget {
  const _AlertTile({required this.alert, required this.onTap});

  final Alert alert;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.ctms;
    final strings = AppStrings.of(context);

    final tone = switch (alert.priority) {
      AlertPriority.critical => colors.critical,
      AlertPriority.high => colors.caution,
      _ => theme.colorScheme.onSurfaceVariant,
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.sm),
      child: Material(
        color: alert.isUnread
            ? theme.colorScheme.surfaceContainerHighest
            : theme.colorScheme.surface,
        borderRadius: BorderRadius.circular(Radii.md),
        child: InkWell(
          borderRadius: BorderRadius.circular(Radii.md),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(Spacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    AppIconView(
                      alert.priority.isUrgent ? AppIcon.warning : AppIcon.alerts,
                      size: IconSize.sm,
                      color: tone,
                    ),
                    const SizedBox(width: Spacing.sm),
                    Expanded(
                      child: Text(
                        alert.title,
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight:
                              alert.isUnread ? FontWeight.bold : FontWeight.normal,
                        ),
                      ),
                    ),
                    // Unread is carried by weight and a chip, not by colour
                    // alone.
                    if (alert.isUnread)
                      StatusChip(
                        label: strings.alertsNew,
                        tone: StatusTone.info,
                        icon: AppIcon.alerts,
                        dense: true,
                      ),
                  ],
                ),
                const SizedBox(height: Spacing.xs),
                Text(alert.body, style: theme.textTheme.bodyMedium),
                if (alert.createdAt != null) ...[
                  const SizedBox(height: Spacing.xs),
                  Text(
                    _when(context, alert.createdAt!),
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// Local time, which for a driver in Andhra means IST. The server stores UTC
  /// and this is the edge that converts it.
  String _when(BuildContext context, DateTime at) {
    final local = at.toLocal();
    final now = DateTime.now();
    final sameDay = local.year == now.year &&
        local.month == now.month &&
        local.day == now.day;

    final time = '${local.hour.toString().padLeft(2, '0')}:'
        '${local.minute.toString().padLeft(2, '0')}';

    return sameDay
        ? time
        : '${local.day.toString().padLeft(2, '0')}/'
            '${local.month.toString().padLeft(2, '0')} $time';
  }
}

/// The first read failed and there is nothing to fall back on.
class _CouldNotRead extends StatelessWidget {
  const _CouldNotRead(this.failure);

  final ApiFailure failure;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Padding(
      padding: const EdgeInsets.all(Spacing.md),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ConsequencePanel(
            severity: ConsequenceSeverity.warning,
            title: strings.errorTitle,
            // The server's own words where it gave any, the network's
            // otherwise. Neither is improved by paraphrasing.
            body: failure.message,
          ),
          const SizedBox(height: Spacing.md),
          SizedBox(
            height: Sizes.buttonHeight,
            child: OutlinedButton(
              onPressed: () => context.read<AlertsCubit>().load(),
              child: Text(strings.errorRetry),
            ),
          ),
        ],
      ),
    );
  }
}

/// The list on screen is older than it looks.
class _StaleNotice extends StatelessWidget {
  const _StaleNotice(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Row(
      children: [
        AppIconView(AppIcon.warning, size: IconSize.sm, color: colors.caution),
        const SizedBox(width: Spacing.sm),
        Expanded(
          child: Text(
            AppStrings.of(context).alertsStale,
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: colors.caution),
          ),
        ),
      ],
    );
  }
}
