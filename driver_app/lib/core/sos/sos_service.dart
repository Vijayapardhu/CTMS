import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../features/incidents/data/incident_api.dart';
import '../../features/incidents/domain/incident.dart';
import '../api/api_failure.dart';
import '../services/logger_service.dart';
import '../sync/drift_sync_queue.dart';
import '../sync/sync_cubit.dart';
import '../sync/sync_engine.dart';
import '../sync/sync_queue.dart';

/// Where an emergency call would go, and how to reach it.
///
/// Supplied at build time, because no endpoint in the frozen contract returns
/// a control-room number and inventing one would put a fake number in front of
/// a driver in an emergency. Absent, the app says the number is not configured
/// rather than dialling nothing.
class EmergencyContact {
  const EmergencyContact({this.phone});

  final String? phone;

  bool get isConfigured => phone != null && phone!.isNotEmpty;

  static const fromEnvironment = EmergencyContact(
    phone: String.fromEnvironment('EMERGENCY_PHONE'),
  );
}

/// M5 — the emergency alert.
sealed class SosState extends Equatable {
  const SosState();

  @override
  List<Object?> get props => const [];
}

/// Nothing raised.
final class SosIdle extends SosState {
  const SosIdle();
}

/// Written down locally, being sent.
final class SosSending extends SosState {
  const SosSending();
}

/// The server has it.
final class SosSent extends SosState {
  const SosSent({required this.incidentId, this.message});

  final String incidentId;
  final String? message;

  @override
  List<Object?> get props => [incidentId];
}

/// Held on the phone. Not sent, and never described as sent.
final class SosQueued extends SosState {
  const SosQueued({required this.at, this.latitude, this.longitude});

  final DateTime at;

  /// The last position known when the alert was raised, for the SMS fallback.
  final double? latitude;
  final double? longitude;

  bool get hasPosition => latitude != null && longitude != null;

  @override
  List<Object?> get props => [at, latitude, longitude];
}

/// The server refused it. Its words, verbatim.
final class SosRefused extends SosState {
  const SosRefused(this.message);

  final String message;

  @override
  List<Object?> get props => [message];
}

/// Raises the alarm.
///
/// Deliberately application-level and owned by nobody's screen. A driver must
/// be able to raise this with no trip running, no GPS, and no network, so it
/// cannot depend on the trip machine, the map, or anything that needs a
/// server round trip to exist.
class SosService extends Cubit<SosState> {
  SosService({
    required IncidentApi api,
    required DriftSyncQueue queue,
    required SyncCubit sync,
    required LoggerService logger,
    this.contact = EmergencyContact.fromEnvironment,
  })  : _api = api,
        _queue = queue,
        _sync = sync,
        _logger = logger,
        super(const SosIdle());

  final IncidentApi _api;
  final DriftSyncQueue _queue;
  final SyncCubit _sync;
  final LoggerService _logger;

  final EmergencyContact contact;

  /// The type the server uses for an emergency alert. Life safety, so the
  /// contract accepts it with no description at all.
  static const type = 'SOS';

  /// Raises the alarm.
  ///
  /// The order matters and is the whole point: the report is written to the
  /// queue **before** anything is sent. A phone that dies mid-transmission has
  /// still recorded that the driver pressed the button, and the alert goes the
  /// moment the app runs again.
  ///
  /// [tripId] is optional. So is the position. Neither is worth withholding an
  /// emergency for.
  Future<void> raise({
    String? tripId,
    double? latitude,
    double? longitude,
  }) async {
    if (state is SosSending) return;

    final key = _queue.newIdempotencyKey();
    final at = DateTime.now().toUtc();

    final report = IncidentReport(
      type: type,
      idempotencyKey: key,
      reportedAt: at,
      tripId: tripId,
      latitude: latitude,
      longitude: longitude,
    );

    // Written down first, always. Removed again only once the server has
    // acknowledged it.
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

    emit(const SosSending());

    try {
      final outcome = IncidentOutcome.fromEnvelope(await _api.report(report));

      await _queue.resolve(key, ReplayOutcome.accepted);
      await _sync.refresh();

      emit(SosSent(incidentId: outcome.id, message: outcome.message));
    } on ApiFailure catch (e) {
      // A refusal is the server declining, not a delivery failure: the alert
      // will not succeed on a retry, so it does not stay queued pretending it
      // might.
      if (e is ConflictFailure || e is ValidationFailure || e is ForbiddenFailure) {
        await _queue.resolve(key, ReplayOutcome.refused, message: e.message);
        await _sync.refresh();
        emit(SosRefused(e.message));
        return;
      }

      // Everything else — no signal, a dead server — leaves the alert queued
      // under the key it already has, so the replay is the same alert.
      _logger.warn('SOS could not be sent; queued', context: {'error': e.message});
      emit(SosQueued(at: at, latitude: latitude, longitude: longitude));
    }
  }

  /// Rings the transport office.
  ///
  /// Returns false when no number is configured, so the caller can say that
  /// rather than appearing to dial.
  Future<bool> call() async {
    if (!contact.isConfigured) return false;

    return launchUrl(Uri(scheme: 'tel', path: contact.phone));
  }

  /// Sends the office a text with the last position known.
  ///
  /// The message is pre-filled but not sent automatically — the platform hands
  /// it to the driver to send, which is the only way an app is allowed to do
  /// this and also the right way: they can add a word before it goes.
  Future<bool> sendSms({double? latitude, double? longitude}) async {
    if (!contact.isConfigured) return false;

    final position = latitude != null && longitude != null
        ? ' Last known position: $latitude, $longitude'
        : ' Position unavailable.';

    return launchUrl(Uri(
      scheme: 'sms',
      path: contact.phone,
      queryParameters: {'body': 'CTMS emergency from the driver app.$position'},
    ));
  }

  void reset() => emit(const SosIdle());
}
