/// The state of an emergency alert.
///
/// There is deliberately no `failed`. An alert that cannot be sent is
/// [queued] — retrying, with device-native fallbacks offered — because telling
/// somebody in an emergency that their alert failed, with no alternative, is
/// the worst outcome this app can produce.
sealed class SosState {
  const SosState();
}

class SosIdle extends SosState {
  const SosIdle();
}

/// Written to local storage, before any network call was attempted.
class SosPersisted extends SosState {
  const SosPersisted(this.localId);
  final String localId;
}

class SosSending extends SosState {
  const SosSending(this.localId);
  final String localId;
}

/// No signal, or the request failed. Retrying, and offering a telephone call
/// and an SMS with the last known coordinates.
class SosQueued extends SosState {
  const SosQueued(this.localId, {required this.attempts});
  final String localId;
  final int attempts;
}

/// Operations has it.
class SosActive extends SosState {
  const SosActive(this.incidentId, {required this.acknowledgedAt});
  final String incidentId;
  final DateTime acknowledgedAt;
}

/// Withdrawn by the driver. Recorded, never erased.
class SosCancelled extends SosState {
  const SosCancelled(this.incidentId);
  final String incidentId;
}

/// Emergency alerting.
///
/// **This is infrastructure, not a feature.** It is registered beside the API
/// client and the sync queue rather than with the feature blocs, and three
/// things follow from that:
///
/// * Any code path may [raise] it — a hardware button, a voice intent, an
///   accessibility action — without touching the UI layer.
/// * It is initialised at app start, so a queued alert from a previous session
///   resumes retrying before any screen exists.
/// * It takes no dependency on the trip. `tripId` is optional in the API
///   precisely so a driver walking to a parked bus can still call for help.
///
/// The SOS screen observes this service; it does not own it.
abstract interface class SosService {
  Stream<SosState> get state;
  SosState get current;

  /// Persists locally **before** attempting the network. The process can be
  /// killed mid-request, and an alert that exists only inside a pending call
  /// is an alert that never happened.
  Future<void> raise({String? tripId});

  /// Withdraw a false alarm. A note is required; the record survives.
  Future<void> withdraw(String note);

  /// Resumes retrying anything left queued by a previous session.
  Future<void> restore();
}
