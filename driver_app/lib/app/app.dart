import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../l10n/app_localizations.dart';
import 'di/service_locator.dart';
import 'lifecycle/app_lifecycle_observer.dart';
import 'router/app_router.dart';
import 'settings/app_preferences.dart';
import 'theme/app_theme.dart';

/// The root widget.
///
/// Stateful for one reason: the router and the lifecycle observer must be
/// created once and disposed, and a `StatelessWidget` rebuilding either would
/// reset every tab's navigation stack.
class CtmsDriverApp extends StatefulWidget {
  const CtmsDriverApp({super.key});

  @override
  State<CtmsDriverApp> createState() => _CtmsDriverAppState();
}

class _CtmsDriverAppState extends State<CtmsDriverApp> {
  late final GoRouter _router;
  late final AppLifecycleObserver _lifecycle;

  @override
  void initState() {
    super.initState();
    _router = buildRouter();
    _lifecycle = sl<AppLifecycleObserver>()..attach();
  }

  @override
  void dispose() {
    _lifecycle.detach();
    _router.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final prefs = sl<AppPreferences>();

    return ListenableBuilder(
      listenable: prefs,
      builder: (context, _) => MaterialApp.router(
        title: 'CTMS Driver',
        onGenerateTitle: (context) => AppStrings.of(context).appTitle,
        debugShowCheckedModeBanner: false,
        routerConfig: _router,
        scaffoldMessengerKey: sl<GlobalKey<ScaffoldMessengerState>>(),
        theme: AppTheme.light,
        darkTheme: AppTheme.dark,
        themeMode: prefs.themeMode,
        localizationsDelegates: AppStrings.localizationsDelegates,
        supportedLocales: AppStrings.supportedLocales,
        builder: (context, child) {
          // Text scaling is honoured up to 1.3. Beyond that the boarding
          // counter's two-digit figure stops fitting its 96dp button, and a
          // driver tapping a clipped control is worse than one reading
          // slightly smaller text.
          final scale = MediaQuery.textScalerOf(context).clamp(
            minScaleFactor: 1.0,
            maxScaleFactor: 1.3,
          );

          return MediaQuery(
            data: MediaQuery.of(context).copyWith(textScaler: scale),
            child: child ?? const SizedBox.shrink(),
          );
        },
      ),
    );
  }
}
