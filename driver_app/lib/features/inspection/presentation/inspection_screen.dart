import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/skeleton_loader.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/checklist.dart';
import '../domain/inspection_state.dart';
import 'bloc/inspection_bloc.dart';
import 'widgets/checklist_item_tile.dart';
import 'widgets/quick_check.dart';

/// P9 — the pre-trip checklist.
///
/// Odometer first, with the minimum stated **before** any error. Then one tile
/// per server-supplied item, no defaults anywhere. Review unlocks only when
/// every item is resolved, and its label carries what is still outstanding.
class InspectionScreen extends StatelessWidget {
  const InspectionScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return BlocBuilder<InspectionBloc, InspectionState>(
      builder: (context, state) {
        return PopScope(
          // Fourteen items entered on a phone in the cold is real work.
          canPop: state.draft?.hasAnyAnswer != true,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop) _confirmDiscard(context);
          },
          child: Scaffold(
            appBar: AppBar(title: Text(strings.quickTitle)),
            body: switch (state) {
              // Scrollable: fourteen placeholder rows are taller than any
              // handset, and a Column would simply overflow.
              InspectionLoading() => const SingleChildScrollView(
                  padding: EdgeInsets.all(Spacing.md),
                  child: SkeletonLoader(shape: SkeletonShape.list, count: 14),
                ),
              InspectionUnavailable() => _Unavailable(state),
              InspectionEditing(revealed: false) => _Quick(state),
              InspectionEditing() => _Checklist(state),
              // Review, submitting, submitted and saved own their own screens;
              // this one holds the last editable view underneath them.
              _ => const SizedBox.shrink(),
            },
            bottomNavigationBar: state is InspectionEditing && state.revealed
                ? _ReviewBar(state)
                : null,
          ),
        );
      },
    );
  }

  static Future<bool> _confirmDiscard(BuildContext context) async {
    final strings = AppStrings.of(context);
    final bloc = context.read<InspectionBloc>();
    final navigator = Navigator.of(context);

    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(strings.inspectionDiscardTitle),
        content: Text(strings.inspectionDiscardBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(strings.inspectionKeep),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(strings.inspectionDiscard),
          ),
        ],
      ),
    );

    if (discard ?? false) {
      bloc.add(const DraftDiscarded());
      navigator.pop();
      return true;
    }

    return false;
  }
}

/// The normal shape of P9: confirm the odometer, then one deliberate action.
class _Quick extends StatelessWidget {
  const _Quick(this.state);

  final InspectionEditing state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final bloc = context.read<InspectionBloc>();
    final draft = state.value;
    final failures = draft.failures(state.checklist);
    final ready = draft.odometer != null;

    return ListView(
      padding: const EdgeInsets.all(Spacing.md),
      children: [
        if (state.rejection != null) _Rejection(state),
        Text(
          strings.quickBus,
          style: theme.textTheme.labelLarge
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        Text(bloc.busLabel, style: theme.textTheme.headlineSmall),
        const SizedBox(height: Spacing.lg),
        OdometerConfirmation(
          recorded: bloc.minimumOdometer,
          value: draft.odometer,
          onChanged: (v) => bloc.add(OdometerEntered(v)),
        ),
        const Divider(height: Spacing.xxl),

        // Already reported something, then came back here.
        if (failures.isNotEmpty) ...[
          InspectionSummary(
            passed: draft.passedCount(state.checklist),
            total: state.checklist.length,
            issues: failures.length,
          ),
          const SizedBox(height: Spacing.md),
          for (final item in failures) _IssueLine(item),
          const SizedBox(height: Spacing.lg),
        ],

        Text(strings.quickPrompt, style: theme.textTheme.titleMedium),
        const SizedBox(height: Spacing.md),
        // Held back until the odometer is settled: the reading is part of the
        // record, and "all OK" without it is not a complete statement.
        AbsorbPointer(
          absorbing: !ready,
          child: Opacity(
            opacity: ready ? 1 : 0.4,
            child: AllOkAction(
              onPressed: () => bloc.add(const AllOkDeclared()),
            ),
          ),
        ),
        if (!ready) ...[
          const SizedBox(height: Spacing.sm),
          Text(
            strings.quickOdometerUnknown,
            style: theme.textTheme.bodySmall
                ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
          ),
        ],
        const SizedBox(height: Spacing.lg),
        Center(
          child: TextButton(
            onPressed: () => bloc.add(const ChecklistRevealed()),
            child: Text(strings.quickSomethingWrong),
          ),
        ),
      ],
    );
  }
}

/// One reported problem, as it appears on the quick screen.
class _IssueLine extends StatelessWidget {
  const _IssueLine(this.item);

  final ChecklistItem item;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.only(bottom: Spacing.xs),
      child: Row(
        children: [
          AppIconView(AppIcon.error, size: IconSize.sm, color: colors.critical),
          const SizedBox(width: Spacing.sm),
          Expanded(
            child: Text(
              '${item.label} \u00b7 ${AppStrings.of(context).quickNotOk}',
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

class _Checklist extends StatelessWidget {
  const _Checklist(this.state);

  final InspectionEditing state;

  @override
  Widget build(BuildContext context) {
    final bloc = context.read<InspectionBloc>();

    return ListView(
      padding: const EdgeInsets.all(Spacing.md),
      children: [
        if (state.rejection != null) _Rejection(state),
        Text(
          AppStrings.of(context).quickWhatIsWrong,
          style: Theme.of(context).textTheme.titleMedium,
        ),
        const SizedBox(height: Spacing.md),
        if (state.target == RejectionTarget.odometer) ...[
          _Odometer(state),
          const SizedBox(height: Spacing.lg),
        ],
        for (final item in state.checklist)
          ChecklistItemTile(
            item: item,
            answer: state.value.answers[item.code],
            problem: state.value.problemWith(item),
            highlighted: state.rejectedItem == item.code,
            onVerdict: (v) => bloc.add(ItemAnswered(item.code, v)),
            onNotes: (n) => bloc.add(ItemNotesChanged(item.code, n)),
          ),
      ],
    );
  }
}

/// The server's refusal, shown where it belongs.
class _Rejection extends StatelessWidget {
  const _Rejection(this.state);

  final InspectionEditing state;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;

    return Container(
      margin: const EdgeInsets.only(bottom: Spacing.md),
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
            // Verbatim. The backend writes these for drivers.
            child: Text(
              state.rejection!.message,
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

class _Odometer extends StatelessWidget {
  const _Odometer(this.state);

  final InspectionEditing state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final bloc = context.read<InspectionBloc>();
    final minimum = bloc.minimumOdometer;

    return TextFormField(
      initialValue: state.value.odometer?.toString(),
      keyboardType: TextInputType.number,
      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
      onChanged: (v) => bloc.add(OdometerEntered(int.tryParse(v))),
      autofocus: state.target == RejectionTarget.odometer,
      decoration: InputDecoration(
        labelText: strings.inspectionOdometer,
        // Stated up front, not held back until the driver gets it wrong.
        helperText: minimum == null
            ? null
            : strings.inspectionOdometerMinimum('$minimum'),
        errorText: state.target == RejectionTarget.odometer
            ? state.rejection?.message
            : null,
      ),
    );
  }
}

/// Review is disabled until the checklist is resolved, and says how many are
/// left rather than simply refusing.
class _ReviewBar extends StatelessWidget {
  const _ReviewBar(this.state);

  final InspectionEditing state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final remaining = state.value.unresolved(state.checklist).length;
    final odometerMissing = state.value.odometer == null;
    final blocked = remaining > 0 || odometerMissing;

    return SafeArea(
      minimum: const EdgeInsets.all(Spacing.md),
      child: SizedBox(
        height: Sizes.buttonProminent,
        child: FilledButton(
          onPressed: blocked
              ? null
              : () => context.read<InspectionBloc>().add(const ReviewRequested()),
          child: Text(
            odometerMissing
                ? strings.inspectionOdometerRequired
                : remaining > 0
                    ? strings.inspectionReviewRemaining(remaining)
                    : strings.inspectionReview,
          ),
        ),
      ),
    );
  }
}

class _Unavailable extends StatelessWidget {
  const _Unavailable(this.state);

  final InspectionUnavailable state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Padding(
      padding: const EdgeInsets.all(Spacing.lg),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          AppIconView(AppIcon.error, size: IconSize.sos, color: colors.critical),
          const SizedBox(height: Spacing.lg),
          Text(
            strings.inspectionUnavailableTitle,
            style: theme.textTheme.headlineSmall,
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: Spacing.sm),
          Text(
            state.emptyChecklist
                ? strings.inspectionEmptyChecklist
                : state.reason.message,
            style: theme.textTheme.bodyMedium,
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}
