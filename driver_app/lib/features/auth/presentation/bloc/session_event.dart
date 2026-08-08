import 'package:equatable/equatable.dart';

import '../../domain/session.dart';
import '../../domain/session_state.dart';

/// Everything that can move M0.
sealed class SessionEvent extends Equatable {
  const SessionEvent();

  @override
  List<Object?> get props => [];
}

/// Read stored tokens and decide where the app starts. Fired once, at launch.
final class SessionStarted extends SessionEvent {
  const SessionStarted();
}

/// Credentials submitted.
final class SessionLoginRequested extends SessionEvent {
  const SessionLoginRequested({required this.email, required this.password});

  final String email;
  final String password;

  @override
  List<Object?> get props => [email, password];

  /// The password is never in a string this class produces.
  @override
  String toString() => 'SessionLoginRequested($email)';
}

/// Sign out of this device.
final class SessionLogoutRequested extends SessionEvent {
  const SessionLogoutRequested();
}

/// Sign out of every device.
final class SessionLogoutEverywhereRequested extends SessionEvent {
  const SessionLogoutEverywhereRequested();
}

/// The driver acknowledged the expiry screen. Moves `expired` →
/// `unauthenticated`, which is the only way out of that state.
final class SessionExpiryAcknowledged extends SessionEvent {
  const SessionExpiryAcknowledged();
}

/// A token exchange has begun. Moves `authenticated` → `refreshing`.
final class SessionRefreshBegan extends SessionEvent {
  const SessionRefreshBegan();
}

/// A refresh that started somewhere else succeeded.
final class SessionRenewedExternally extends SessionEvent {
  const SessionRenewedExternally(this.session);

  final Session session;

  @override
  List<Object?> get props => [session];
}

/// The session was revoked by something other than a user action — a refusal
/// discovered while a trip request was in flight.
final class SessionRevokedExternally extends SessionEvent {
  const SessionRevokedExternally(this.reason, {this.message});

  final SessionEndReason reason;
  final String? message;

  @override
  List<Object?> get props => [reason, message];
}
