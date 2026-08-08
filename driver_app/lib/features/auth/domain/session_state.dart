import 'package:equatable/equatable.dart';

import 'session.dart';

/// Why a session ended. Drives the wording on the expired screen, which must
/// not say "your session expired" when the real answer is that an
/// administrator switched the account off.
enum SessionEndReason {
  /// The refresh token was refused. Ordinary expiry.
  refreshRefused,

  /// The server rejected an authenticated request even after a good refresh.
  /// From the client this is indistinguishable from deactivation, and the
  /// backend deliberately will not say which — so the wording covers both.
  accountUnavailable,

  /// The driver signed out on this device.
  signedOut,

  /// The driver signed out of every device.
  signedOutEverywhere,
}

/// M0 from `docs/driver-app/04-state-machines.md`, as a sealed hierarchy.
///
/// Every state in the diagram is a class here, and every class is reachable —
/// a state with no path into it is a state that will render wrong at 06:40.
sealed class SessionState extends Equatable {
  const SessionState();

  /// The session, when there is one. `null` in every state where the driver is
  /// not signed in.
  Session? get session => null;

  bool get isAuthenticated => session != null;

  @override
  List<Object?> get props => [];
}

/// Reading stored tokens. The splash screen.
final class SessionInitialising extends SessionState {
  const SessionInitialising();
}

/// No credentials. The login screen.
final class SessionUnauthenticated extends SessionState {
  const SessionUnauthenticated();
}

/// Credentials submitted, waiting on the server.
final class SessionAuthenticating extends SessionState {
  const SessionAuthenticating();
}

/// Login was refused. Carries the server's own wording.
///
/// A separate state rather than a flag on [SessionUnauthenticated], so the
/// error cannot survive a rebuild and reappear on a screen the driver already
/// dismissed it from.
final class SessionLoginFailed extends SessionState {
  const SessionLoginFailed(this.message, {this.fieldErrors = const {}});

  final String message;
  final Map<String, List<String>> fieldErrors;

  @override
  List<Object?> get props => [message, fieldErrors];
}

/// Signed in and verified against the server.
final class SessionAuthenticated extends SessionState {
  const SessionAuthenticated(this._session);

  final Session _session;

  @override
  Session get session => _session;

  @override
  List<Object?> get props => [_session];
}

/// Signed in from stored tokens, but the server has not confirmed it because
/// there was no network at launch.
///
/// The app runs. The banner shows. The identity is trusted only as far as the
/// last time the server agreed with it — which is why this is a distinct state
/// rather than [SessionAuthenticated] with a flag.
final class SessionOffline extends SessionState {
  const SessionOffline(this._session);

  final Session _session;

  @override
  Session get session => _session;

  @override
  List<Object?> get props => [_session];
}

/// Exchanging the refresh token.
///
/// Holds the old session so the UI does not blank out mid-trip while a
/// background refresh runs.
final class SessionRefreshing extends SessionState {
  const SessionRefreshing(this._session);

  final Session _session;

  @override
  Session get session => _session;

  @override
  List<Object?> get props => [_session];
}

/// The session is over and the driver has not been told yet.
///
/// Terminal until acknowledged: the router clears the stack and shows the
/// expired screen, and only the driver's acknowledgement moves it to
/// [SessionUnauthenticated].
final class SessionExpired extends SessionState {
  const SessionExpired(this.reason, {this.message});

  final SessionEndReason reason;

  /// The server's wording, when it gave any.
  final String? message;

  @override
  List<Object?> get props => [reason, message];
}
