import 'package:flutter/widgets.dart';

import '../../core/services/logger_service.dart';

/// Watches foreground/background transitions.
///
/// Registered once, at the top of the widget tree. Later slices hang real
/// behaviour off these callbacks — pausing the GPS stream on `paused`, forcing
/// a sync flush on `resumed` — and this class exists now so there is one place
/// for that to go, rather than a `WidgetsBindingObserver` mixin in each of four
/// features.
class AppLifecycleObserver extends WidgetsBindingObserver {
  AppLifecycleObserver(this._logger);

  final LoggerService _logger;

  final _listeners = <void Function(AppLifecycleState)>[];

  AppLifecycleState _state = AppLifecycleState.resumed;

  /// The most recent state. Read by a service that starts after a transition
  /// has already happened.
  AppLifecycleState get state => _state;

  /// Registers [listener] and returns the function that removes it.
  ///
  /// The remover is handed back rather than left to be looked up, because a
  /// listener that outlives its owner keeps a dead bloc alive.
  VoidCallback addListener(void Function(AppLifecycleState) listener) {
    _listeners.add(listener);
    return () => _listeners.remove(listener);
  }

  void attach() => WidgetsBinding.instance.addObserver(this);
  void detach() => WidgetsBinding.instance.removeObserver(this);

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _state = state;
    _logger.debug('Lifecycle', context: {'state': state.name});

    // Copied before iterating: a listener may remove itself in response.
    for (final listener in List.of(_listeners)) {
      listener(state);
    }
  }
}
