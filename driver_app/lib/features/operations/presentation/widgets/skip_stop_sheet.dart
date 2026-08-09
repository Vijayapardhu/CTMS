import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design_system/tokens.dart';
import '../../../../l10n/app_localizations.dart';

/// S4 — skipping a stop, with the reason the server requires.
///
/// Owns its own [TextEditingController]. That is the whole reason this is a
/// widget rather than a `ConfirmSheet` with a text field passed in: a
/// controller disposed by the caller the moment the sheet pops is still being
/// read by the field during the exit animation, and Flutter asserts on it.
///
/// The confirm action stays disabled until the reason is long enough, rather
/// than accepting the tap and quietly doing nothing. Five characters is the
/// server's floor and the helper text says so before the driver types.
class SkipStopSheet extends StatefulWidget {
  const SkipStopSheet({required this.stopName, super.key});

  final String stopName;

  /// Shows the sheet and resolves to the reason, or null if the driver backed
  /// out by any route — cancel, swipe, or the system back gesture.
  static Future<String?> show(BuildContext context, {required String stopName}) {
    return showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => Padding(
        // Lifts the sheet above the keyboard, which covers the confirm button
        // on a handset otherwise.
        padding: EdgeInsets.only(
          bottom: MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SkipStopSheet(stopName: stopName),
      ),
    );
  }

  @override
  State<SkipStopSheet> createState() => _SkipStopSheetState();
}

class _SkipStopSheetState extends State<SkipStopSheet> {
  final _controller = TextEditingController();

  /// The server's own minimum for this field.
  static const _minimum = 5;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

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
            Text(
              strings.opsSkipTitle(widget.stopName),
              style: theme.textTheme.headlineSmall,
            ),
            const SizedBox(height: Spacing.sm),
            Text(strings.opsSkipBody, style: theme.textTheme.bodyLarge),
            const SizedBox(height: Spacing.lg),
            TextField(
              controller: _controller,
              autofocus: true,
              maxLength: 500,
              minLines: 2,
              maxLines: 4,
              textCapitalization: TextCapitalization.sentences,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                labelText: strings.opsSkipReason,
                helperText: strings.opsSkipReasonHint,
                helperMaxLines: 2,
              ),
            ),
            const SizedBox(height: Spacing.lg),
            SizedBox(
              height: Sizes.buttonProminent,
              child: FilledButton(
                onPressed: _controller.text.trim().length < _minimum
                    ? null
                    : () {
                        HapticFeedback.mediumImpact();
                        Navigator.of(context).pop(_controller.text.trim());
                      },
                style: FilledButton.styleFrom(
                  backgroundColor: theme.colorScheme.error,
                  foregroundColor: theme.colorScheme.onError,
                ),
                child: Text(
                  strings.opsSkipConfirm,
                  style: theme.textTheme.titleLarge
                      ?.copyWith(fontWeight: FontWeight.bold),
                ),
              ),
            ),
            const SizedBox(height: Spacing.sm),
            SizedBox(
              height: Sizes.buttonHeight,
              child: TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: Text(strings.cancel),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
