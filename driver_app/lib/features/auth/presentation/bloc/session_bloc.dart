import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/services/analytics_service.dart';
import '../../../../core/services/crash_reporter.dart';
import '../../data/session_manager.dart';
import '../../domain/session_state.dart';
import 'session_event.dart';

export 'session_event.dart';

/// M0, driven.
///
/// The bloc decides *states*; [SessionManager] holds the *tokens*. Keeping
/// those apart is what lets the API client refresh a token without a bloc in
/// the call stack, and lets the bloc be tested without a network.
class SessionBloc extends Bloc<SessionEvent, SessionState> {
  SessionBloc({
    required SessionManager manager,
    required CrashReporter crashReporter,
    required AnalyticsService analytics,
  })  : _manager = manager,
        _crashReporter = crashReporter,
        _analytics = analytics,
        super(const SessionInitialising()) {
    on<SessionStarted>(_onStarted);
    on<SessionLoginRequested>(_onLogin);
    on<SessionLogoutRequested>(_onLogout);
    on<SessionLogoutEverywhereRequested>(_onLogoutEverywhere);
    on<SessionExpiryAcknowledged>(_onExpiryAcknowledged);
    on<SessionRefreshBegan>(_onRefreshBegan);
    on<SessionRenewedExternally>(_onRenewedExternally);
    on<SessionRevokedExternally>(_onRevokedExternally);

    _signals = _manager.signals.listen((signal) {
      switch (signal) {
        case SessionRefreshStarted():
          add(const SessionRefreshBegan());
        case SessionRenewed(:final session):
          add(SessionRenewedExternally(session));
        case SessionRevoked(:final reason, :final message):
          add(SessionRevokedExternally(reason, message: message));
      }
    });
  }

  final SessionManager _manager;
  final CrashReporter _crashReporter;
  final AnalyticsService _analytics;

  late final StreamSubscription<SessionSignal> _signals;

  final _notices = StreamController<ApiFailure>.broadcast();

  /// Failures that describe an *action*, not the session.
  ///
  /// A refused "sign out everywhere" leaves the driver signed in — the state
  /// is unchanged and correct, so there is no state to render the message in.
  /// The screen that started the action shows it and moves on.
  Stream<ApiFailure> get notices => _notices.stream;

  Future<void> _onStarted(SessionStarted event, Emitter<SessionState> emit) async {
    emit(const SessionInitialising());
    emit(await _manager.restore());
    await _identify();
  }

  Future<void> _onLogin(
    SessionLoginRequested event,
    Emitter<SessionState> emit,
  ) async {
    emit(const SessionAuthenticating());

    try {
      final session = await _manager.login(
        email: event.email.trim(),
        password: event.password,
      );

      emit(SessionAuthenticated(session));
      await _identify();
      await _analytics.track('session_started');
    } on ValidationFailure catch (e) {
      emit(SessionLoginFailed(e.message, fieldErrors: e.fieldErrors));
    } on ApiFailure catch (e) {
      // The server's wording, verbatim. It is written so that a failure never
      // reveals whether the address is registered, and paraphrasing it here is
      // how that property gets lost.
      emit(SessionLoginFailed(e.message));
    }
  }

  Future<void> _onLogout(
    SessionLogoutRequested event,
    Emitter<SessionState> emit,
  ) async {
    await _manager.logout();
    await _crashReporter.setUserIdentifier(null);

    emit(const SessionUnauthenticated());
  }

  Future<void> _onLogoutEverywhere(
    SessionLogoutEverywhereRequested event,
    Emitter<SessionState> emit,
  ) async {
    try {
      await _manager.logoutEverywhere();
      await _crashReporter.setUserIdentifier(null);

      emit(const SessionUnauthenticated());
    } on ApiFailure catch (e) {
      // The state is deliberately left alone: the driver is still signed in
      // here, and saying otherwise would tell them their other devices are
      // safe when they are not. The failure goes out on [notices] instead,
      // because it is a message about an action, not a change of session.
      if (!_notices.isClosed) _notices.add(e);
    }
  }

  void _onExpiryAcknowledged(
    SessionExpiryAcknowledged event,
    Emitter<SessionState> emit,
  ) {
    if (state is! SessionExpired) return;

    emit(const SessionUnauthenticated());
  }

  void _onRefreshBegan(SessionRefreshBegan event, Emitter<SessionState> emit) {
    final session = state.session;

    // Only from a signed-in state. A refresh discovered during `restore` runs
    // before any session state exists, and blanking the splash for it would
    // flash a spinner over a spinner.
    if (session == null || state is SessionRefreshing) return;

    emit(SessionRefreshing(session));
  }

  void _onRenewedExternally(
    SessionRenewedExternally event,
    Emitter<SessionState> emit,
  ) {
    // A renewal only matters while signed in. Arriving in `unauthenticated`
    // means a logout raced it, and the logout wins.
    if (!state.isAuthenticated) return;

    emit(SessionAuthenticated(event.session));
  }

  Future<void> _onRevokedExternally(
    SessionRevokedExternally event,
    Emitter<SessionState> emit,
  ) async {
    await _crashReporter.setUserIdentifier(null);

    emit(SessionExpired(event.reason, message: event.message));
  }

  /// Attaches the driver's opaque id to crash reports. Never their name,
  /// email or telephone number.
  Future<void> _identify() async {
    await _crashReporter.setUserIdentifier(state.session?.user.id);
  }

  @override
  Future<void> close() async {
    await _signals.cancel();
    await _notices.close();
    return super.close();
  }
}
