import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../core/connectivity/connectivity_cubit.dart';
import '../features/auth/presentation/bloc/session_bloc.dart';
import '../features/trip/presentation/bloc/trip_bloc.dart';
import '../l10n/app_localizations.dart';
import 'di/service_locator.dart';
import 'lifecycle/app_lifecycle_observer.dart';
import 'router/app_router.dart';
import 'settings/app_preferences.dart';
import 'theme/app_theme.dart';

/// The root widget.
///
/// Stateful for one reason: the router and the lifecycle observer must be
/// created once and disposed. A `StatelessWidget` rebuilding either would reset
/// every tab's navigation stack.
class CtmsDriverApp extends StatefulWidget {
  const CtmsDriverApp({super.key});

  @override
  State<CtmsDriverApp> createState() => _CtmsDriverAppState();
}

class _CtmsDriverAppState extends State<CtmsDriverApp> {
  late final SessionBloc _session;
  late final GoRouter _router;
  late final AppLifecycleObserver _lifecycle;

  @override
  void initState() {
    super.initState();

    _session = sl<SessionBloc>();
    _router = buildRouter(session: _session);
    _lifecycle = sl<AppLifecycleObserver>()..attach();

    // Reading stored tokens is the first thing the app does. Everything before
    // this point is a splash screen.
    _session.add(const SessionStarted());
  }

  @override
  void dispose() {
    _lifecycle.detach();
    _router.dispose();
    // The bloc is owned by the locator, not by this widget — the session
    // outlives the widget tree on a hot restart.
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final prefs = sl<AppPreferences>();

    // Both are app-scoped and provided above the router, per the Bloc mapping
    // in `docs/driver-app/04-state-machines.md`. A driver switching tabs must
    // not restart either one.
    return MultiBlocProvider(
      providers: [
        BlocProvider<SessionBloc>.value(value: _session),
        BlocProvider<ConnectivityCubit>.value(value: sl<ConnectivityCubit>()),
        BlocProvider<TripBloc>.value(value: sl<TripBloc>()),
      ],
      child: ListenableBuilder(
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
          // Text scaling is deliberately *not* clamped here. A global cap
          // denies a driver with low vision the setting they chose, for the
          // sake of a handful of controls. Those controls wrap themselves in
          // ConstrainedTextScale instead.
        ),
      ),
    );
  }
}
