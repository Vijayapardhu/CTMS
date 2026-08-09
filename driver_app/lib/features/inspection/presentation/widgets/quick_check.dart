import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design_system/ctms_colors.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/icons/app_icons.dart';
import '../../../../l10n/app_localizations.dart';

/// The one deliberate act that stands for a whole clean inspection.
///
/// Dominant on purpose. A driver in a yard at 06:00 who has walked round the
/// bus and found nothing wrong should not have to hunt for the way to say so,
/// and should certainly not have to say it fourteen times.
///
/// It is **not** a default: nothing is selected when the screen opens, and this
/// cannot be reached without a tap. See `06-component-library.md` §3.
class AllOkAction extends StatelessWidget {
  const AllOkAction({required this.onPressed, super.key});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final colors = context.ctms;

    return Semantics(
      button: true,
      // Says what it does, not what colour it is.
      label: strings.quickAllOkSemantics,
      excludeSemantics: true,
      child: SizedBox(
        // Well past the 48dp floor: this is the target a gloved thumb finds
        // without looking.
        height: 96,
        width: double.infinity,
        child: FilledButton(
          onPressed: () {
            HapticFeedback.mediumImpact();
            onPressed();
          },
          style: FilledButton.styleFrom(
            backgroundColor: colors.positive,
            foregroundColor: colors.onPositive,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(Radii.md),
            ),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // The tick carries the meaning as well as the green does.
              AppIconView(
                AppIcon.success,
                size: IconSize.lg,
                color: colors.onPositive,
              ),
              const SizedBox(width: Spacing.md),
              Text(
                strings.quickAllOk,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      color: colors.onPositive,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The bus's recorded total, offered rather than demanded.
///
/// Typing five digits on a phone in the cold is the kind of friction that gets
/// a number invented rather than read. The API already knows what the bus last
/// recorded, so the driver confirms it — and can still correct it, because the
/// dashboard is the truth and the server is the arbiter.
class OdometerConfirmation extends StatefulWidget {
  const OdometerConfirmation({
    required this.recorded,
    required this.value,
    required this.onChanged,
    super.key,
  });

  /// What the bus last reported. Null when the API did not supply one.
  final int? recorded;

  /// What the draft currently holds.
  final int? value;

  final ValueChanged<int?> onChanged;

  @override
  State<OdometerConfirmation> createState() => _OdometerConfirmationState();
}

class _OdometerConfirmationState extends State<OdometerConfirmation> {
  late bool _editing = widget.recorded == null && widget.value == null;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final settled = widget.value;

    if (_editing) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextFormField(
            initialValue: (settled ?? widget.recorded)?.toString(),
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            autofocus: true,
            onChanged: (v) => widget.onChanged(int.tryParse(v)),
            decoration: InputDecoration(
              labelText: strings.inspectionOdometer,
              helperText: widget.recorded == null
                  ? null
                  : strings.inspectionOdometerMinimum('${widget.recorded}'),
            ),
          ),
          const SizedBox(height: Spacing.sm),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: settled == null
                  ? null
                  : () => setState(() => _editing = false),
              child: Text(strings.quickOdometerContinue),
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          strings.inspectionOdometer,
          style: theme.textTheme.labelLarge
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: Spacing.xs),
        Text(
          strings.quickOdometerReading('${settled ?? widget.recorded}'),
          style: theme.textTheme.headlineSmall,
        ),
        const SizedBox(height: Spacing.sm),
        Row(
          children: [
            if (settled == null)
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => widget.onChanged(widget.recorded),
                  icon: const AppIconView(AppIcon.success, size: IconSize.sm),
                  label: Text(strings.quickOdometerCorrect),
                ),
              )
            else
              Expanded(
                child: Row(
                  children: [
                    AppIconView(
                      AppIcon.success,
                      size: IconSize.sm,
                      color: context.ctms.positive,
                    ),
                    const SizedBox(width: Spacing.sm),
                    Text(
                      strings.quickOdometerCorrect,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: context.ctms.positive),
                    ),
                  ],
                ),
              ),
            const SizedBox(width: Spacing.sm),
            TextButton(
              onPressed: () => setState(() => _editing = true),
              child: Text(strings.quickOdometerEdit),
            ),
          ],
        ),
      ],
    );
  }
}

/// How the check currently stands, counted from the server's own list.
class InspectionSummary extends StatelessWidget {
  const InspectionSummary({
    required this.passed,
    required this.total,
    required this.issues,
    super.key,
  });

  final int passed;
  final int total;
  final int issues;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;
    final clean = issues == 0;

    return Row(
      children: [
        AppIconView(
          clean ? AppIcon.success : AppIcon.warning,
          size: IconSize.sm,
          color: clean ? colors.positive : colors.caution,
        ),
        const SizedBox(width: Spacing.sm),
        Expanded(
          child: Text(
            clean
                ? strings.quickChecksOk(passed, total)
                : '${strings.quickChecksOk(passed, total)} · '
                    '${strings.quickIssues(issues)}',
            style: theme.textTheme.titleMedium,
          ),
        ),
      ],
    );
  }
}
