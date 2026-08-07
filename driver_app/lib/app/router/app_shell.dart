import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/connectivity/connectivity_service.dart';
import '../../core/design_system/ctms_colors.dart';
import '../../core/design_system/tokens.dart';
import '../../core/icons/app_icons.dart';
import '../../l10n/app_localizations.dart';
import '../di/service_locator.dart';

/// The persistent chrome around the four tabs.
///
/// Owns the bottom navigation and the offline banner. The banner lives here
/// rather than per-screen because connectivity is a property of the app, and a
/// driver must be able to see it from any tab without going looking.
class AppShell extends StatelessWidget {
  const AppShell({required this.navigationShell, super.key});

  final StatefulNavigationShell navigationShell;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      body: Column(
        children: [
          const _OfflineBanner(),
          Expanded(child: navigationShell),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: navigationShell.currentIndex,
        onDestinationSelected: (index) => navigationShell.goBranch(
          index,
          // Tapping the active tab returns to its root, which is the only way
          // back out of a deep stack for a driver who has stopped reading.
          initialLocation: index == navigationShell.currentIndex,
        ),
        destinations: [
          NavigationDestination(
            icon: const AppIconView(AppIcon.trip, excludeSemantics: true),
            label: strings.tabTrip,
          ),
          NavigationDestination(
            icon: const AppIconView(AppIcon.map, excludeSemantics: true),
            label: strings.tabMap,
          ),
          NavigationDestination(
            icon: const AppIconView(AppIcon.alerts, excludeSemantics: true),
            label: strings.tabAlerts,
          ),
          NavigationDestination(
            icon: const AppIconView(AppIcon.profile, excludeSemantics: true),
            label: strings.tabMe,
          ),
        ],
      ),
    );
  }
}

/// Shown whenever the API is unreachable.
///
/// Colour is never the only signal — the icon carries the same meaning, per
/// `docs/driver-app/07-design-system.md`.
class _OfflineBanner extends StatelessWidget {
  const _OfflineBanner();

  @override
  Widget build(BuildContext context) {
    final connectivity = sl<ConnectivityService>();

    return StreamBuilder<Reachability>(
      stream: connectivity.changes,
      initialData: connectivity.current,
      builder: (context, snapshot) {
        if (snapshot.data != Reachability.offline) {
          return const SizedBox.shrink();
        }

        final colors = context.ctms;

        return Material(
          color: colors.caution,
          child: SafeArea(
            bottom: false,
            child: Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: Spacing.md,
                vertical: Spacing.sm,
              ),
              child: Row(
                children: [
                  AppIconView(
                    AppIcon.offline,
                    size: IconSize.sm,
                    color: colors.onCaution,
                  ),
                  const SizedBox(width: Spacing.sm),
                  Expanded(
                    child: Text(
                      AppStrings.of(context).offlineBanner,
                      style: Theme.of(context)
                          .textTheme
                          .labelMedium
                          ?.copyWith(color: colors.onCaution),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
