import 'package:flutter/material.dart';

import '../design_system/tokens.dart';

/// Application-level UI affordances that are not owned by any one screen.
///
/// A single navigator key rather than a passed-around [BuildContext], so a
/// service layer can surface a message without a widget reference — the sync
/// queue reporting a rejected action does not have a screen.
class UiService {
  UiService(this.navigatorKey, this.messengerKey);

  final GlobalKey<NavigatorState> navigatorKey;
  final GlobalKey<ScaffoldMessengerState> messengerKey;

  BuildContext? get _context => navigatorKey.currentContext;

  /// A transient message.
  ///
  /// Used for a refusal during a running trip — never a dialog, because a
  /// dialog blocks and a driver at a stop with people boarding cannot be
  /// blocked.
  void snack(String message, {String? actionLabel, VoidCallback? onAction}) {
    messengerKey.currentState
      ?..clearSnackBars()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          duration: onAction == null
              ? Motion.snackbar
              : Motion.snackbarWithAction,
          action: onAction == null
              ? null
              : SnackBarAction(label: actionLabel ?? 'Retry', onPressed: onAction),
        ),
      );
  }

  /// A blocking decision. Returns null if dismissed.
  Future<bool?> confirm({
    required String title,
    required String message,
    String confirmLabel = 'Confirm',
    String cancelLabel = 'Cancel',
    bool danger = false,
  }) async {
    final context = _context;
    if (context == null) return null;

    return showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: Text(message),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(cancelLabel),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: danger
                ? FilledButton.styleFrom(
                    backgroundColor: Theme.of(context).colorScheme.error,
                    foregroundColor: Theme.of(context).colorScheme.onError,
                  )
                : null,
            child: Text(confirmLabel),
          ),
        ],
      ),
    );
  }

  /// A bottom sheet. Dismissible unless [dismissible] is false, which is
  /// reserved for an active emergency alert.
  Future<T?> sheet<T>({
    required WidgetBuilder builder,
    bool dismissible = true,
  }) async {
    final context = _context;
    if (context == null) return null;

    return showModalBottomSheet<T>(
      context: context,
      isScrollControlled: true,
      isDismissible: dismissible,
      enableDrag: dismissible,
      useSafeArea: true,
      builder: builder,
    );
  }
}
