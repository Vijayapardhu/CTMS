import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/di/service_locator.dart';
import '../../../core/api/api_failure.dart';
import '../../../app/settings/app_preferences.dart';
import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/services/ui_service.dart';
import '../../../l10n/app_localizations.dart';
import '../../auth/domain/session_state.dart';
import '../../auth/presentation/bloc/session_bloc.dart';

/// R4 — the profile tab.
///
/// Slice 1 gives it the account section and the two ways out of a session.
/// Trip history, documents and the rest arrive with their own slices.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  StreamSubscription<ApiFailure>? _notices;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    // A refused "sign out everywhere" changes no state, so there is nothing
    // for a BlocBuilder to render. It arrives here instead.
    _notices ??= context.read<SessionBloc>().notices.listen((_) {
      if (!mounted) return;
      sl<UiService>().snack(AppStrings.of(context).signOutEverywhereFailed);
    });
  }

  @override
  void dispose() {
    _notices?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final prefs = sl<AppPreferences>();

    return Scaffold(
      appBar: AppBar(title: Text(strings.tabMe)),
      body: ListenableBuilder(
        listenable: prefs,
        builder: (context, _) => ListView(
          padding: const EdgeInsets.only(bottom: Spacing.xl),
          children: [
            _SectionHeader(strings.settingsAccount),
            const _AccountTile(),

            const Divider(),
            _SectionHeader(strings.settingsAppearance),
            for (final (mode, label) in <(ThemeMode, String)>[
              (ThemeMode.system, strings.themeSystem),
              (ThemeMode.light, strings.themeLight),
              (ThemeMode.dark, strings.themeDark),
            ])
              RadioListTile<ThemeMode>(
                value: mode,
                groupValue: prefs.themeMode,
                onChanged: (selected) {
                  if (selected != null) prefs.setThemeMode(selected);
                },
                title: Text(label),
              ),

            if (prefs.canToggleDeveloperMode) ...[
              const Divider(),
              SwitchListTile(
                secondary: const AppIconView(AppIcon.settings),
                title: Text(strings.developerMode),
                subtitle: Text(strings.developerModeHint),
                value: prefs.developerMode,
                onChanged: prefs.setDeveloperMode,
              ),
            ],

            const Divider(),
            const _SignOutTile(),
            const _SignOutEverywhereTile(),
          ],
        ),
      ),
    );
  }
}

/// Who is signed in, and whether the server has confirmed it.
class _AccountTile extends StatelessWidget {
  const _AccountTile();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return BlocBuilder<SessionBloc, SessionState>(
      builder: (context, state) {
        final user = state.session?.user;
        if (user == null) return const SizedBox.shrink();

        final licence = user.profile?.licenseNumber;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ListTile(
              leading: const AppIconView(AppIcon.account, size: IconSize.lg),
              title: Text(user.fullName),
              subtitle: Text(
                licence == null || licence.isEmpty
                    ? user.email
                    : '${user.email}\n${strings.accountLicence(licence)}',
              ),
              isThreeLine: licence != null && licence.isNotEmpty,
            ),
            // Stated, not hidden. A driver acting on a cached identity should
            // know the server has not agreed with it since last night.
            if (state is SessionOffline)
              Padding(
                padding: const EdgeInsets.fromLTRB(
                    Spacing.md, 0, Spacing.md, Spacing.sm),
                child: Row(
                  children: [
                    AppIconView(
                      AppIcon.offline,
                      size: IconSize.xs,
                      color: context.ctms.caution,
                    ),
                    const SizedBox(width: Spacing.xs),
                    Expanded(
                      child: Text(
                        strings.accountUnconfirmed,
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(color: context.ctms.caution),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        );
      },
    );
  }
}

class _SignOutTile extends StatelessWidget {
  const _SignOutTile();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return ListTile(
      leading: const AppIconView(AppIcon.logout),
      title: Text(strings.signOut),
      onTap: () async {
        final bloc = context.read<SessionBloc>();

        final confirmed = await sl<UiService>().confirm(
          title: strings.signOutConfirmTitle,
          message: strings.signOutConfirmBody,
          confirmLabel: strings.signOut,
          cancelLabel: strings.cancel,
        );

        if (confirmed ?? false) bloc.add(const SessionLogoutRequested());
      },
    );
  }
}

class _SignOutEverywhereTile extends StatelessWidget {
  const _SignOutEverywhereTile();

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return ListTile(
      leading: AppIconView(AppIcon.logout, color: context.ctms.critical),
      title: Text(
        strings.signOutEverywhere,
        style: TextStyle(color: context.ctms.critical),
      ),
      onTap: () async {
        final bloc = context.read<SessionBloc>();

        final confirmed = await sl<UiService>().confirm(
          title: strings.signOutEverywhereConfirmTitle,
          message: strings.signOutEverywhereConfirmBody,
          confirmLabel: strings.signOutEverywhere,
          cancelLabel: strings.cancel,
          danger: true,
        );

        if (confirmed ?? false) {
          bloc.add(const SessionLogoutEverywhereRequested());
        }
      },
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.label);

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
          Spacing.md, Spacing.md, Spacing.md, Spacing.sm),
      child: Text(
        label,
        style: Theme.of(context)
            .textTheme
            .labelLarge
            ?.copyWith(color: Theme.of(context).colorScheme.primary),
      ),
    );
  }
}
