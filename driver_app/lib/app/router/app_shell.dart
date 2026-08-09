import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../core/connectivity/connectivity_cubit.dart';
import '../../core/connectivity/connectivity_service.dart';
import '../../core/icons/app_icons.dart';
import '../../core/widgets/persistent_banner.dart';
import '../../l10n/app_localizations.dart';

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
          // Above the tabs rather than inside any one of them: connectivity is
          // a property of the app, and a driver must see it from wherever they
          // happen to be standing.
          const OfflineBanner(),
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

/// C2 — the offline banner.
///
/// Not dismissible, because dismissing it would not reconnect anything: the
/// condition outlives the gesture, and hiding it would leave a driver queueing
/// boardings with no sign of why.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<ConnectivityCubit, Reachability>(
      builder: (context, reachability) {
        final offline = reachability == Reachability.offline;

        return AnimatedPersistentBanner(
          visible: offline,
          banner: PersistentBanner(
            severity: BannerSeverity.caution,
            // The registry's own glyph for this condition. A generic warning
            // triangle would say "something is wrong"; this says what.
            icon: AppIcon.offline,
            message: AppStrings.of(context).offlineBanner,
          ),
        );
      },
    );
  }
}
