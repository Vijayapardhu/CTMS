import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../design_system/tokens.dart';

/// Component 19 — `ConfirmSheet`.
///
/// The one-tap confirmation in front of an act that changes something outside
/// the phone: starting a trip, ending one, skipping a stop.
///
/// It is a sheet rather than a dialog because a driver holds the phone in one
/// hand at the depot gate, and the bottom of the screen is the part a thumb
/// reaches. The confirm action sits there, at [Sizes.buttonProminent] when the
/// act is irreversible; the cancel is a plain text button above it, deliberately
/// quieter but never hidden.
///
/// Dismissal by swipe or by tapping outside is always allowed — the sheet is a
/// checkpoint, not a trap. Callers that must not be dismissed (an active SOS)
/// do not use this component.
class ConfirmSheet extends StatelessWidget {
  const ConfirmSheet({
    required this.title,
    required this.confirmLabel,
    required this.cancelLabel,
    this.body,
    this.danger = false,
    this.child,
    super.key,
  });

  final String title;

  /// Supporting line. One sentence — a driver at a gate does not read two
  /// paragraphs, and a sheet that needs them is asking the wrong question.
  final String? body;

  final String confirmLabel;
  final String cancelLabel;

  /// Colours the confirm action as destructive.
  final bool danger;

  /// Anything the decision depends on: the departure time, the odometer, the
  /// stop being skipped. Sits between the body and the actions.
  final Widget? child;

  /// Shows the sheet and resolves to true only when the driver confirmed.
  ///
  /// Returns false for every other outcome — swipe, tap outside, back — so a
  /// caller can treat "not true" as "did not agree" without a null check.
  static Future<bool> show(
    BuildContext context, {
    required String title,
    required String confirmLabel,
    required String cancelLabel,
    String? body,
    bool danger = false,
    Widget? child,
  }) async {
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => ConfirmSheet(
        title: title,
        body: body,
        confirmLabel: confirmLabel,
        cancelLabel: cancelLabel,
        danger: danger,
        child: child,
      ),
    );

    return confirmed ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Deliberately not text-scale clamped: this is prose and a pair of
    // buttons that can grow, not a control with a fixed spatial budget.
    return Padding(
        padding: const EdgeInsets.fromLTRB(
          Spacing.lg,
          0,
          Spacing.lg,
          Spacing.lg,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(title, style: theme.textTheme.headlineSmall),
              if (body != null) ...[
                const SizedBox(height: Spacing.sm),
                Text(body!, style: theme.textTheme.bodyLarge),
              ],
              if (child != null) ...[
                const SizedBox(height: Spacing.lg),
                child!,
              ],
              const SizedBox(height: Spacing.xl),
              SizedBox(
                height: Sizes.buttonProminent,
                child: FilledButton(
                  onPressed: () {
                    HapticFeedback.mediumImpact();
                    Navigator.of(context).pop(true);
                  },
                  style: danger
                      ? FilledButton.styleFrom(
                          backgroundColor: theme.colorScheme.error,
                          foregroundColor: theme.colorScheme.onError,
                        )
                      : null,
                  child: Text(
                    confirmLabel,
                    style: theme.textTheme.titleLarge
                        ?.copyWith(fontWeight: FontWeight.bold),
                  ),
                ),
              ),
              const SizedBox(height: Spacing.sm),
              SizedBox(
                height: Sizes.buttonHeight,
                child: TextButton(
                  onPressed: () => Navigator.of(context).pop(false),
                  child: Text(cancelLabel),
                ),
              ),
            ],
          ),
        ),
    );
  }
}
