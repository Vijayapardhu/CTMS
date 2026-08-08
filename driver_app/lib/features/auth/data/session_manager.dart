import 'dart:async';

import '../../../core/api/api_failure.dart';
import '../../../core/api/session_delegate.dart';
import '../../../core/services/logger_service.dart';
import '../domain/session.dart';
import '../domain/session_state.dart';
import 'auth_api.dart';
import 'session_store.dart';

/// Something happened to the session that no screen asked for.
///
/// A refresh triggered by a trip request, or an expiry discovered while the
/// driver was looking at the map. The bloc listens so its state follows the
/// truth even when the change did not start with a user action.
sealed class SessionSignal {
  const SessionSignal();
}

/// A refresh has started.
///
/// Emitted so the `refreshing` state in M0 is a state the app actually passes
/// through and can render, rather than a gap between two other states.
final class SessionRefreshStarted extends SessionSignal {
  const SessionRefreshStarted();
}

/// A background refresh succeeded.
final class SessionRenewed extends SessionSignal {
  const SessionRenewed(this.session);

  final Session session;
}

/// The session is over.
final class SessionRevoked extends SessionSignal {
  const SessionRevoked(this.reason, {this.message});

  final SessionEndReason reason;
  final String? message;
}

/// Owns the tokens.
///
/// The single place that knows what the current session is. The API client
/// asks it for a token and asks it to refresh; the bloc asks it to sign in and
/// out. Nothing else touches storage.
class SessionManager implements SessionDelegate {
  SessionManager({
    required AuthApi api,
    required SessionStore store,
    required LoggerService logger,
    DateTime Function()? clock,
  })  : _api = api,
        _store = store,
        _logger = logger,
        _now = clock ?? (() => DateTime.now().toUtc());

  final AuthApi _api;
  final SessionStore _store;
  final LoggerService _logger;
  final DateTime Function() _now;

  final _signals = StreamController<SessionSignal>.broadcast();

  Session? _current;

  /// In-flight refresh. Every caller awaits this one future, so a burst of
  /// simultaneous 401s produces exactly one call to `/auth/refresh`. Each
  /// refresh consumes its token server-side, so racing refreshes would
  /// invalidate each other and sign the driver out mid-trip.
  Future<bool>? _refreshInFlight;

  /// Out-of-band session changes.
  Stream<SessionSignal> get signals => _signals.stream;

  Session? get current => _current;

  // ── SessionDelegate ────────────────────────────────────────────────────

  /// The token to attach.
  ///
  /// Refreshes pre-emptively when the access token is within its skew window,
  /// so the driver does not pay a failed round trip at the moment they tap
  /// "boarded".
  @override
  Future<String?> accessToken() async {
    final session = _current;
    if (session == null) return null;

    if (session.tokens.isExpiredAt(_now())) {
      final renewed = await refreshSession();
      if (!renewed) return null;
      return _current?.tokens.accessToken;
    }

    return session.tokens.accessToken;
  }

  @override
  Future<bool> refreshSession() {
    // Join the running refresh rather than starting a second one.
    return _refreshInFlight ??= _performRefresh().whenComplete(() {
      _refreshInFlight = null;
    });
  }

  @override
  Future<void> onSessionExpired() =>
      _end(SessionEndReason.accountUnavailable, notify: true);

  // ── Session lifecycle ──────────────────────────────────────────────────

  /// Reads stored tokens and decides which M0 state the app starts in.
  ///
  /// Returns [SessionUnauthenticated] with no tokens, [SessionAuthenticated]
  /// when the server confirms the identity, and [SessionOffline] when there
  /// are tokens but no network — a driver at a depot with no signal must still
  /// be able to open the app they were signed into last night.
  Future<SessionState> restore() async {
    final stored = await _store.read();
    if (stored == null) return const SessionUnauthenticated();

    _current = stored;

    try {
      final user = await _api.me(await accessToken() ?? '');
      final confirmed = stored.copyWith(user: user);

      // The refresh inside accessToken() may already have replaced the pair.
      _current = confirmed.copyWith(tokens: _current!.tokens);
      await _store.write(_current!);

      return SessionAuthenticated(_current!);
    } on NetworkFailure {
      _logger.info('No network at launch; running from the cached session');
      return SessionOffline(stored);
    } on AuthFailure catch (e) {
      await _end(SessionEndReason.refreshRefused, message: e.message);
      return SessionExpired(SessionEndReason.refreshRefused, message: e.message);
    }
  }

  /// Signs in. Throws [ApiFailure] for the bloc to translate.
  Future<Session> login({required String email, required String password}) async {
    final session = await _api.login(email: email, password: password);

    _current = session;
    await _store.write(session);

    return session;
  }

  /// Signs out of this device.
  ///
  /// The server call is best-effort: a driver who taps sign-out on a bus with
  /// no signal must still end up signed out locally. The token is revoked
  /// server-side on its next use anyway, and leaving them signed in because
  /// the network was down is the worse failure.
  Future<void> logout() async {
    final token = _current?.tokens.accessToken;

    if (token != null) {
      try {
        await _api.logout(token);
      } on ApiFailure catch (e) {
        _logger.warn('Sign-out was not acknowledged', context: {
          'failure': e.runtimeType.toString(),
        });
      }
    }

    await _end(SessionEndReason.signedOut);
  }

  /// Signs out of every device.
  ///
  /// Unlike [logout] this is **not** best-effort. The driver is asking to
  /// revoke a session on a handset they may no longer hold; reporting success
  /// when the server never heard the request would be a lie about a security
  /// action. The failure propagates.
  Future<void> logoutEverywhere() async {
    final token = _current?.tokens.accessToken;
    if (token == null) return;

    await _api.logoutEverywhere(token);
    await _end(SessionEndReason.signedOutEverywhere);
  }

  /// Discards the session locally and, when [notify] is set, tells listeners.
  Future<void> _end(
    SessionEndReason reason, {
    String? message,
    bool notify = false,
  }) async {
    _current = null;
    await _store.clear();

    if (notify && !_signals.isClosed) {
      _signals.add(SessionRevoked(reason, message: message));
    }
  }

  Future<bool> _performRefresh() async {
    final token = _current?.tokens.refreshToken;
    if (token == null) return false;

    if (!_signals.isClosed) _signals.add(const SessionRefreshStarted());

    try {
      final renewed = await _api.refresh(token);

      // Keep the identity already held: `/auth/refresh` returns the user too,
      // but the cached profile may be fresher than a mid-flight response.
      _current = renewed.copyWith(user: _current?.user ?? renewed.user);
      await _store.write(_current!);

      if (!_signals.isClosed) _signals.add(SessionRenewed(_current!));
      return true;
    } on NetworkFailure {
      // Not an expiry. The token may be perfectly good; there is simply no
      // network. Ending the session here would sign a driver out of a moving
      // bus for driving through a tunnel.
      _logger.info('Refresh could not reach the server');
      return false;
    } on ApiFailure catch (e) {
      await _end(SessionEndReason.refreshRefused, message: e.message, notify: true);
      return false;
    }
  }

  Future<void> dispose() => _signals.close();
}
