import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/connectivity/connectivity_cubit.dart';
import '../../../core/connectivity/connectivity_service.dart';
import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../l10n/app_localizations.dart';
import '../domain/session_state.dart';
import 'bloc/session_bloc.dart';

/// P1 — sign in.
///
/// The only screen a driver can reach without a session. Deliberately plain:
/// two fields and one button, sized for a gloved thumb in a depot at 06:00.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _passwordFocus = FocusNode();

  bool _obscured = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _passwordFocus.dispose();
    super.dispose();
  }

  void _submit() {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    FocusScope.of(context).unfocus();

    context.read<SessionBloc>().add(
          SessionLoginRequested(
            email: _email.text,
            password: _password.text,
          ),
        );
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: BlocBuilder<SessionBloc, SessionState>(
          builder: (context, state) {
            final busy = state is SessionAuthenticating;

            return Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(Spacing.lg),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: Sizes.maxLineLength),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        AppIconView(
                          AppIcon.trip,
                          size: IconSize.sos,
                          color: theme.colorScheme.primary,
                        ),
                        const SizedBox(height: Spacing.md),
                        Text(strings.loginTitle, style: theme.textTheme.headlineMedium),
                        const SizedBox(height: Spacing.xs),
                        Text(strings.loginSubtitle, style: theme.textTheme.bodyMedium),
                        const SizedBox(height: Spacing.xl),

                        if (state is SessionLoginFailed)
                          _FailureNotice(state.message),

                        TextFormField(
                          controller: _email,
                          enabled: !busy,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.next,
                          autocorrect: false,
                          autofillHints: const [AutofillHints.username],
                          decoration: InputDecoration(labelText: strings.loginEmail),
                          validator: (value) => _validateEmail(value, strings),
                          onFieldSubmitted: (_) => _passwordFocus.requestFocus(),
                        ),
                        const SizedBox(height: Spacing.md),

                        TextFormField(
                          controller: _password,
                          focusNode: _passwordFocus,
                          enabled: !busy,
                          obscureText: _obscured,
                          textInputAction: TextInputAction.done,
                          autofillHints: const [AutofillHints.password],
                          decoration: InputDecoration(
                            labelText: strings.loginPassword,
                            suffixIcon: IconButton(
                              onPressed: () => setState(() => _obscured = !_obscured),
                              // A driver who cannot see what they typed retries
                              // until the 5/min throttle locks them out.
                              tooltip: _obscured
                                  ? strings.loginShowPassword
                                  : strings.loginHidePassword,
                              icon: AppIconView(
                                _obscured ? AppIcon.passwordShow : AppIcon.passwordHide,
                              ),
                            ),
                          ),
                          validator: (value) => (value ?? '').isEmpty
                              ? strings.loginPasswordRequired
                              : null,
                          onFieldSubmitted: (_) => _submit(),
                        ),
                        const SizedBox(height: Spacing.xl),

                        SizedBox(
                          height: Sizes.buttonProminent,
                          child: FilledButton(
                            onPressed: busy ? null : _submit,
                            child: busy
                                ? const SizedBox.square(
                                    dimension: IconSize.sm,
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : Text(strings.loginSubmit),
                          ),
                        ),

                        BlocBuilder<ConnectivityCubit, Reachability>(
                          builder: (context, reachability) {
                            if (reachability != Reachability.offline) {
                              return const SizedBox.shrink();
                            }

                            return Padding(
                              padding: const EdgeInsets.only(top: Spacing.md),
                              child: Text(
                                // Sign-in is the one action that genuinely
                                // cannot be queued: there is no identity to
                                // queue it under.
                                strings.loginOffline,
                                style: theme.textTheme.bodySmall
                                    ?.copyWith(color: context.ctms.caution),
                                textAlign: TextAlign.center,
                              ),
                            );
                          },
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }

  String? _validateEmail(String? value, AppStrings strings) {
    final email = (value ?? '').trim();

    if (email.isEmpty) return strings.loginEmailRequired;

    // Deliberately permissive. The server is the authority on whether an
    // address exists; this only catches a typo before spending one of the five
    // attempts a driver gets each minute.
    if (!email.contains('@') || email.startsWith('@') || email.endsWith('@')) {
      return strings.loginEmailInvalid;
    }

    return null;
  }
}

/// The server's refusal, shown verbatim.
class _FailureNotice extends StatelessWidget {
  const _FailureNotice(this.message);

  final String message;

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
          // Colour is never the only signal.
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
