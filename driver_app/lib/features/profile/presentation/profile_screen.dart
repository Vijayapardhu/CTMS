import 'package:flutter/material.dart';

import '../../../app/di/service_locator.dart';
import '../../../app/settings/app_preferences.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../l10n/app_localizations.dart';

/// R4 — the profile tab.
///
/// Slice 0 gives it the two settings the foundation actually owns: appearance
/// and developer mode. Profile data itself arrives with the session in a later
/// slice.
class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final prefs = sl<AppPreferences>();

    return Scaffold(
      appBar: AppBar(title: Text(strings.tabMe)),
      body: ListenableBuilder(
        listenable: prefs,
        builder: (context, _) => ListView(
          padding: const EdgeInsets.symmetric(vertical: Spacing.sm),
          children: [
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
          ],
        ),
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.label);

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(Spacing.md, Spacing.md, Spacing.md, Spacing.sm),
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

