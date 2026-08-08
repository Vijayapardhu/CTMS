import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/session_state.dart';
import 'bloc/session_bloc.dart';

/// P4 — the session ended.
///
/// A dead end by design. The stack is cleared before this screen appears, so a
/// driver cannot swipe back into a trip screen that will only produce 401s.
///
/// The wording depends on *why*. "Your session expired" is wrong and
/// frightening when the real answer is that an administrator deactivated the
/// account, and a driver told the wrong thing rings the wrong person.
class SessionExpiredScreen extends StatelessWidget {
  const SessionExpiredScreen({required this.reason, this.serverMessage, super.key});

  final SessionEndReason reason;

  /// The server's own wording, preferred over ours when it gave any.
  final String? serverMessage;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(Spacing.lg),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: Sizes.maxLineLength),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  AppIconView(
                    AppIcon.sessionEnded,
                    size: IconSize.sos,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  const SizedBox(height: Spacing.md),
                  Text(strings.expiredTitle, style: theme.textTheme.headlineMedium),
                  const SizedBox(height: Spacing.sm),
                  Text(
                    serverMessage ?? _body(strings),
                    style: theme.textTheme.bodyMedium,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: Spacing.xl),
                  SizedBox(
                    width: double.infinity,
                    height: Sizes.buttonProminent,
                    child: FilledButton(
                      onPressed: () => context
                          .read<SessionBloc>()
                          .add(const SessionExpiryAcknowledged()),
                      child: Text(strings.expiredContinue),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  String _body(AppStrings strings) => switch (reason) {
        SessionEndReason.accountUnavailable => strings.expiredAccountUnavailable,
        // A deliberate sign-out never reaches this screen, but the branch
        // exists rather than a default, so adding a reason forces a decision
        // about what it says.
        SessionEndReason.refreshRefused ||
        SessionEndReason.signedOut ||
        SessionEndReason.signedOutEverywhere =>
          strings.expiredRefreshRefused,
      };
}
