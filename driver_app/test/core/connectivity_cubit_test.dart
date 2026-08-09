import 'dart:async';

import 'package:ctms_driver/core/connectivity/connectivity_cubit.dart';
import 'package:ctms_driver/core/connectivity/connectivity_service.dart';
import 'package:flutter_test/flutter_test.dart';

/// A service the test drives by hand.
///
/// The real one listens to a platform channel; what matters here is only that
/// the cubit follows whatever the service decides, so the decision is made
/// directly.
class _StubService implements ConnectivityService {
  _StubService([this._current = Reachability.online]);

  Reachability _current;
  final _controller = StreamController<Reachability>.broadcast();

  @override
  Stream<Reachability> get changes => _controller.stream;

  @override
  Reachability get current => _current;

  @override
  void recordFailure() {}

  @override
  void recordSuccess() {}

  void emit(Reachability value) {
    _current = value;
    _controller.add(value);
  }

  Future<void> dispose() => _controller.close();
}

void main() {
  group('ConnectivityCubit (M7)', () {
    late _StubService service;
    late ConnectivityCubit cubit;

    setUp(() {
      service = _StubService();
      cubit = ConnectivityCubit(service);
    });

    tearDown(() async {
      await cubit.close();
      await service.dispose();
    });

    test('starts from what the service already knows', () {
      expect(
        cubit.state,
        Reachability.online,
        reason: 'a cubit that starts at a guess would show the banner for one '
            'frame at every launch',
      );
      expect(cubit.isOffline, isFalse);
    });

    test('adopts the service state at construction, not a fixed default',
        () async {
      final offlineAtStart = _StubService(Reachability.offline);
      final late = ConnectivityCubit(offlineAtStart);

      expect(late.state, Reachability.offline);

      await late.close();
      await offlineAtStart.dispose();
    });

    test('follows the service losing the API', () async {
      final seen = <Reachability>[];
      final sub = cubit.stream.listen(seen.add);

      service.emit(Reachability.offline);
      await Future<void>.delayed(Duration.zero);

      expect(seen, [Reachability.offline]);
      expect(cubit.state, Reachability.offline);
      expect(cubit.isOffline, isTrue);

      await sub.cancel();
    });

    test('follows it back — offline is not a terminal state', () async {
      final seen = <Reachability>[];
      final sub = cubit.stream.listen(seen.add);

      service
        ..emit(Reachability.offline)
        ..emit(Reachability.online);
      await Future<void>.delayed(Duration.zero);

      expect(seen, [Reachability.offline, Reachability.online]);
      expect(cubit.state, Reachability.online);

      await sub.cancel();
    });

    test('does not re-emit a state it is already in', () async {
      final seen = <Reachability>[];
      final sub = cubit.stream.listen(seen.add);

      service
        ..emit(Reachability.offline)
        ..emit(Reachability.offline)
        ..emit(Reachability.offline);
      await Future<void>.delayed(Duration.zero);

      expect(
        seen,
        [Reachability.offline],
        reason: 'every subscriber rebuilds on each emission; repeating an '
            'unchanged state rebuilds the whole shell for nothing',
      );

      await sub.cancel();
    });

    test('stops listening once closed', () async {
      await cubit.close();

      // Would throw "Cannot emit new states after calling close" if the
      // subscription outlived the cubit — which is what happens when a
      // long-lived service outlives a short-lived listener.
      expect(() => service.emit(Reachability.offline), returnsNormally);
    });
  });
}
