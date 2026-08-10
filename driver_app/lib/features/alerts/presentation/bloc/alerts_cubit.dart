import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../data/alerts_api.dart';
import '../../domain/alert.dart';

/// R3 — what the office has told this driver.
final class AlertsState extends Equatable {
  const AlertsState({
    this.alerts = const [],
    this.unread = 0,
    this.loading = true,
    this.failure,
  });

  final List<Alert> alerts;

  /// From the server's own count rather than counted here, so the badge agrees
  /// with what the office sees.
  final int unread;

  final bool loading;

  /// Why the last read did not land. The list already held stays on screen.
  final ApiFailure? failure;

  bool get isEmpty => alerts.isEmpty && !loading && failure == null;

  AlertsState copyWith({
    List<Alert>? alerts,
    int? unread,
    bool? loading,
    ApiFailure? failure,
    bool clearFailure = false,
  }) {
    return AlertsState(
      alerts: alerts ?? this.alerts,
      unread: unread ?? this.unread,
      loading: loading ?? this.loading,
      failure: clearFailure ? null : (failure ?? this.failure),
    );
  }

  @override
  List<Object?> get props => [alerts, unread, loading, failure];
}

/// Reads the notification list.
class AlertsCubit extends Cubit<AlertsState> {
  AlertsCubit({required AlertsApi api})
      : _api = api,
        super(const AlertsState());

  final AlertsApi _api;

  Future<void> load() async {
    emit(state.copyWith(loading: state.alerts.isEmpty, clearFailure: true));

    try {
      final alerts = await _api.list();
      final unread = await _api.unreadCount();

      emit(state.copyWith(alerts: alerts, unread: unread, loading: false));
    } on ApiFailure catch (e) {
      // Whatever was read before stays on screen. An alert already delivered
      // does not stop being true because the next poll failed.
      emit(state.copyWith(loading: false, failure: e));
    }
  }

  /// Marks one as read, optimistically.
  ///
  /// The badge is the server's number, so it is decremented locally only to
  /// keep the two in step until the next read replaces both.
  Future<void> markRead(Alert alert) async {
    if (!alert.isUnread) return;

    final now = DateTime.now().toUtc();
    emit(state.copyWith(
      alerts: [
        for (final a in state.alerts) a.id == alert.id ? a.asRead(now) : a,
      ],
      unread: state.unread > 0 ? state.unread - 1 : 0,
    ));

    try {
      await _api.markRead(alert.id);
    } on ApiFailure {
      // Put it back: the server did not record it, so neither should the list.
      await load();
    }
  }

  Future<void> markAllRead() async {
    try {
      await _api.markAllRead();
    } on ApiFailure {
      // Fall through to the reload, which shows what actually happened.
    }

    await load();
  }
}
