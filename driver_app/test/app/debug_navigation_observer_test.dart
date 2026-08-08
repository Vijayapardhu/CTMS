import 'package:ctms_driver/app/router/debug_navigation_observer.dart';
import 'package:ctms_driver/core/services/logger_service.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Captures what would have been logged.
class RecordingLogger implements LoggerService {
  final List<String> lines = [];

  @override
  void debug(String message, {Map<String, Object?>? context}) =>
      lines.add(message);

  @override
  void info(String message, {Map<String, Object?>? context}) =>
      lines.add(message);

  @override
  void warn(String message, {Map<String, Object?>? context}) =>
      lines.add(message);

  @override
  void error(String message, {Object? error, StackTrace? stackTrace}) =>
      lines.add(message);
}

MaterialPageRoute<void> route(String name) => MaterialPageRoute<void>(
      settings: RouteSettings(name: name),
      builder: (_) => const SizedBox.shrink(),
    );

void main() {
  group('DebugNavigationObserver', () {
    late RecordingLogger logger;
    late DebugNavigationObserver observer;

    setUp(() {
      logger = RecordingLogger();
      observer = DebugNavigationObserver(logger);
    });

    test('records a push', () {
      observer.didPush(route('/trip'), null);

      expect(observer.stack, ['/trip']);
    });

    test('builds the trail the driver actually walked', () {
      observer
        ..didPush(route('/trip'), null)
        ..didPush(route('/trip/inspection'), route('/trip'))
        ..didPop(route('/trip/inspection'), route('/trip'))
        ..didPush(route('/trip/sos'), route('/trip'));

      expect(observer.stack, ['/trip', '/trip/sos']);
    });

    test('logs the whole stack, not just the newest entry', () {
      observer
        ..didPush(route('/trip'), null)
        ..didPush(route('/trip/inspection'), route('/trip'));

      expect(
        logger.lines.last,
        'nav push → /trip › /trip/inspection',
        reason: 'the shape of the stack is what is wrong when navigation is '
            'wrong',
      );
    });

    test('a removed route leaves the trail', () {
      observer
        ..didPush(route('/trip'), null)
        ..didPush(route('/trip/evidence'), route('/trip'))
        ..didRemove(route('/trip/evidence'), route('/trip'));

      expect(observer.stack, ['/trip']);
    });

    test('a replacement swaps in place', () {
      observer
        ..didPush(route('/login'), null)
        ..didReplace(newRoute: route('/trip'), oldRoute: route('/login'));

      expect(observer.stack, ['/trip']);
    });

    test('an anonymous route still appears in the trail', () {
      observer.didPush(
        MaterialPageRoute<void>(builder: (_) => const SizedBox.shrink()),
        null,
      );

      expect(
        observer.stack.single,
        startsWith('<'),
        reason: 'a trail that silently drops unnamed dialogs lies about the '
            'stack',
      );
    });

    test('the exposed stack cannot be mutated by a caller', () {
      observer.didPush(route('/trip'), null);

      expect(() => observer.stack.add('/nowhere'), throwsUnsupportedError);
    });

    test('attaches in a debug test binding and stays silent in release', () {
      final observers = DebugNavigationObserver.attachIfDebug(logger);

      // `flutter test` runs in debug, so this is the debug branch. The release
      // branch is a compile-time constant and cannot be exercised here.
      expect(observers, hasLength(1));
      expect(observers.single, isA<DebugNavigationObserver>());
    });
  });
}
