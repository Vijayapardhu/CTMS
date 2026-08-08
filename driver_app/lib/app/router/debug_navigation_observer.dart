import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import '../../core/services/logger_service.dart';

/// A navigation trace for debug builds.
///
/// Not analytics — analytics answers "which screens are used"; this answers
/// "how did the driver get *here*", which is the question you actually have at
/// two in the afternoon when a deep link lands on the wrong tab or a sheet
/// reappears after a pop.
///
/// The whole trail is logged on every transition rather than just the newest
/// entry, because the shape of the stack is what is wrong when navigation is
/// wrong, and reconstructing it from scattered lines wastes the time the trace
/// was meant to save.
///
/// Silent in release: [attachIfDebug] returns an empty list there, so the
/// observer is never even constructed.
class DebugNavigationObserver extends NavigatorObserver {
  DebugNavigationObserver(this._logger);

  final LoggerService _logger;

  final List<String> _stack = [];

  /// The current trail, outermost first. Exposed for tests and for a
  /// diagnostics screen behind developer mode.
  List<String> get stack => List.unmodifiable(_stack);

  /// The observers to hand to `GoRouter`: this one in debug, nothing in
  /// release.
  static List<NavigatorObserver> attachIfDebug(LoggerService logger) {
    return kDebugMode ? [DebugNavigationObserver(logger)] : const [];
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    _stack.add(_label(route));
    _trace('push');
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    _stack.remove(_label(route));
    _trace('pop');
  }

  @override
  void didRemove(Route<dynamic> route, Route<dynamic>? previousRoute) {
    _stack.remove(_label(route));
    _trace('remove');
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    if (oldRoute != null) _stack.remove(_label(oldRoute));
    if (newRoute != null) _stack.add(_label(newRoute));
    _trace('replace');
  }

  void _trace(String action) {
    _logger.debug('nav $action → ${_stack.join(' › ')}');
  }

  /// The route's own name, or its type when it has none — an anonymous dialog
  /// still has to appear in the trail, or the trail lies about the stack.
  String _label(Route<dynamic> route) {
    final name = route.settings.name;
    if (name != null && name.isNotEmpty) return name;
    return '<${route.runtimeType}>';
  }
}
