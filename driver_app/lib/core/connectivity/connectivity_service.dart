import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';

/// Whether the app can reach the API.
///
/// Deliberately not the same question as "is there a network". A driver on
/// hotel wi-fi with no route to the API is not offline in the operating
/// system's sense but is offline for ours, so a transport-level signal alone
/// would keep the app cheerfully queueing nothing.
enum Reachability {
  /// Reachable, or optimistically assumed so until proven otherwise.
  online,

  /// No transport, or three consecutive failures against the API.
  offline,
}

abstract interface class ConnectivityService {
  Stream<Reachability> get changes;
  Reachability get current;

  /// Called by the API layer on every failure and success, so reachability
  /// reflects the server rather than the radio.
  void recordFailure();
  void recordSuccess();
}

class DefaultConnectivityService implements ConnectivityService {
  DefaultConnectivityService(this._connectivity) {
    _subscription = _connectivity.onConnectivityChanged.listen(_onTransport);
  }

  final Connectivity _connectivity;
  late final StreamSubscription<List<ConnectivityResult>> _subscription;
  final _controller = StreamController<Reachability>.broadcast();

  /// Three consecutive API failures count as offline, matching
  /// `docs/driver-app/04-state-machines.md` M7.
  static const _failureThreshold = 3;

  int _consecutiveFailures = 0;
  Reachability _current = Reachability.online;

  @override
  Stream<Reachability> get changes => _controller.stream;

  @override
  Reachability get current => _current;

  void _onTransport(List<ConnectivityResult> results) {
    final hasTransport =
        results.any((r) => r != ConnectivityResult.none);

    if (!hasTransport) {
      _emit(Reachability.offline);
      return;
    }

    // A transport reappearing is not proof the API is reachable, but it is
    // reason enough to stop assuming it is not and let the next call decide.
    _consecutiveFailures = 0;
    _emit(Reachability.online);
  }

  @override
  void recordFailure() {
    _consecutiveFailures++;

    if (_consecutiveFailures >= _failureThreshold) {
      _emit(Reachability.offline);
    }
  }

  @override
  void recordSuccess() {
    _consecutiveFailures = 0;
    _emit(Reachability.online);
  }

  void _emit(Reachability value) {
    if (_current == value) return;
    _current = value;
    _controller.add(value);
  }

  Future<void> dispose() async {
    await _subscription.cancel();
    await _controller.close();
  }
}
