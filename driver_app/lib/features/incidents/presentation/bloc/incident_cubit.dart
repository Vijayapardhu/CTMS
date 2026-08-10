import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/sync/drift_sync_queue.dart';
import '../../../../core/sync/sync_cubit.dart';
import '../../../../core/sync/sync_engine.dart';
import '../../../../core/sync/sync_queue.dart';
import '../../data/incident_api.dart';
import '../../domain/incident.dart';

/// Reporting an ordinary operational problem.
final class IncidentState extends Equatable {
  const IncidentState({
    this.types = const [],
    this.loading = true,
    this.selected,
    this.description = '',
    this.evidenceId,
    this.vehicleCanContinue,
    this.submitting = false,
    this.outcome,
    this.queued = false,
    this.refusal,
    this.loadFailed = false,
  });

  /// From the server. Empty until the read lands.
  final List<IncidentType> types;
  final bool loading;

  /// The types read failed, so there is nothing to choose from. Distinct from
  /// an empty list, which would be a server with no reportable types at all.
  final bool loadFailed;

  final IncidentType? selected;
  final String description;
  final String? evidenceId;
  final bool? vehicleCanContinue;

  final bool submitting;

  /// What the server made of it, once it has.
  final IncidentOutcome? outcome;

  /// Held on the phone. Never described as reported.
  final bool queued;

  /// The server's refusal, verbatim.
  final String? refusal;

  /// Whether the form has what the server will insist on.
  bool get isComplete {
    final type = selected;
    if (type == null) return false;
    if (type.requiresDescription && description.trim().isEmpty) return false;
    if (type.requiresPhoto && evidenceId == null) return false;

    return true;
  }

  /// Everything grouped the way the picker shows it, in the server's order.
  Map<String, List<IncidentType>> get byClass {
    final grouped = <String, List<IncidentType>>{};

    for (final type in types) {
      grouped.putIfAbsent(type.classLabel, () => []).add(type);
    }

    return grouped;
  }

  IncidentState copyWith({
    List<IncidentType>? types,
    bool? loading,
    bool? loadFailed,
    IncidentType? selected,
    String? description,
    String? evidenceId,
    bool? vehicleCanContinue,
    bool? submitting,
    IncidentOutcome? outcome,
    bool? queued,
    String? refusal,
    bool clearRefusal = false,
    bool clearSelection = false,
    bool clearEvidence = false,
  }) {
    return IncidentState(
      types: types ?? this.types,
      loading: loading ?? this.loading,
      loadFailed: loadFailed ?? this.loadFailed,
      selected: clearSelection ? null : (selected ?? this.selected),
      description: description ?? this.description,
      evidenceId: clearEvidence ? null : (evidenceId ?? this.evidenceId),
      vehicleCanContinue: vehicleCanContinue ?? this.vehicleCanContinue,
      submitting: submitting ?? this.submitting,
      outcome: outcome ?? this.outcome,
      queued: queued ?? this.queued,
      refusal: clearRefusal ? null : (refusal ?? this.refusal),
    );
  }

  @override
  List<Object?> get props => [
        types,
        loading,
        loadFailed,
        selected,
        description,
        evidenceId,
        vehicleCanContinue,
        submitting,
        outcome,
        queued,
        refusal,
      ];
}

/// Drives the incident form.
class IncidentCubit extends Cubit<IncidentState> {
  IncidentCubit({
    required IncidentApi api,
    required DriftSyncQueue queue,
    required SyncCubit sync,
  })  : _api = api,
        _queue = queue,
        _sync = sync,
        super(const IncidentState());

  final IncidentApi _api;
  final DriftSyncQueue _queue;
  final SyncCubit _sync;

  /// The most useful sentence the server gave.
  ///
  /// A 422 envelope says only "The given data was invalid"; the sentence worth
  /// reading — "A photograph is required when reporting Breakdown." — is in the
  /// field errors. A 409 puts it in the message. Either way the driver gets the
  /// server's words, not a paraphrase.
  String _reason(ApiFailure failure) {
    if (failure is ValidationFailure) {
      for (final messages in failure.fieldErrors.values) {
        if (messages.isNotEmpty) return messages.first;
      }
    }

    return failure.message;
  }

  /// Reads the list the picker is built from.
  Future<void> load() async {
    emit(state.copyWith(loading: true, loadFailed: false));

    try {
      final types = await _api.types();
      emit(state.copyWith(types: types, loading: false));
    } on ApiFailure {
      // Nothing to report against. Better said plainly than shown as an empty
      // picker, which reads as "no problems exist".
      emit(state.copyWith(loading: false, loadFailed: true));
    }
  }

  void select(IncidentType type) {
    // Changing type changes what is required, so anything the previous type
    // demanded is cleared rather than silently carried over.
    emit(state.copyWith(
      selected: type,
      clearEvidence: true,
      clearRefusal: true,
    ));
  }

  void describe(String text) => emit(state.copyWith(description: text));

  void attach(String? evidenceId) => emit(
        evidenceId == null
            ? state.copyWith(clearEvidence: true)
            : state.copyWith(evidenceId: evidenceId),
      );

  void setVehicleCanContinue(bool? value) =>
      emit(state.copyWith(vehicleCanContinue: value));

  /// Sends the report.
  Future<void> submit({String? tripId, double? latitude, double? longitude}) async {
    final type = state.selected;
    if (type == null || state.submitting || !state.isComplete) return;

    final key = _queue.newIdempotencyKey();
    final at = DateTime.now().toUtc();

    final report = IncidentReport(
      type: type.type,
      idempotencyKey: key,
      reportedAt: at,
      description: state.description.trim(),
      tripId: tripId,
      latitude: latitude,
      longitude: longitude,
      evidenceId: state.evidenceId,
      vehicleCanContinue: state.vehicleCanContinue,
    );

    emit(state.copyWith(submitting: true, clearRefusal: true));

    try {
      final outcome = IncidentOutcome.fromEnvelope(await _api.report(report));
      emit(state.copyWith(submitting: false, outcome: outcome));
    } on NetworkFailure {
      // Queued under the key already minted. The driver is told it is held,
      // not that it was reported.
      await _queue.enqueue(QueuedAction(
        id: key,
        kind: SyncKinds.incident,
        payload: report.toJson(),
        idempotencyKey: key,
        sequence: 0,
        createdAt: at,
        tripId: tripId,
      ));
      await _sync.refresh();

      emit(state.copyWith(submitting: false, queued: true));
    } on ApiFailure catch (e) {
      // 4xx. Never retried — the server has decided, and sending it again
      // would only ask the same question.
      emit(state.copyWith(submitting: false, refusal: _reason(e)));
    }
  }
}
