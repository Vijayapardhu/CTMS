/// A driver action waiting to reach the server.
///
/// Three properties carry the whole offline contract:
///
/// * [idempotencyKey] is generated once, at enqueue — never per attempt. A key
///   regenerated on retry turns one boarding into five.
/// * [sequence] preserves FIFO order within a trip. A boarding recorded before
///   an arrival must replay in that order or the server refuses it for the
///   wrong reason.
/// * [isCompound] marks an entry that carries more than one call — an incident
///   and its photograph, which cannot be separated because the report cannot
///   cite an evidence id that does not exist yet.
class QueuedAction {
  const QueuedAction({
    required this.id,
    required this.kind,
    required this.payload,
    required this.idempotencyKey,
    required this.sequence,
    required this.createdAt,
    this.tripId,
    this.attempts = 0,
    this.lastFailure,
    this.isCompound = false,
  });

  final String id;
  final String kind;
  final Map<String, Object?> payload;
  final String idempotencyKey;
  final int sequence;
  final DateTime createdAt;
  final String? tripId;
  final int attempts;

  /// The server's own message when this was permanently refused. Shown to the
  /// driver verbatim — a rejected action is never silently dropped.
  final String? lastFailure;

  final bool isCompound;

  bool get hasFailed => lastFailure != null;
}

/// What happened when a queued action was replayed.
enum ReplayOutcome {
  /// Accepted. Remove from the queue.
  accepted,

  /// The server recognised the idempotency key and returned the original.
  ///
  /// **This is success, not a conflict**, and must never be reported to the
  /// driver as a failure — it is the mechanism working.
  duplicate,

  /// A business rule refused permanently. Move to the failed list, keep the
  /// message, and show it.
  refused,

  /// Transient. Keep and back off.
  retry,

  /// The trip closed underneath the queue. Purge that trip's entries.
  tripClosed,
}

/// The offline queue.
///
/// The interface is defined in Slice 0 so features can be written against it;
/// the Drift-backed implementation arrives with the first slice that needs to
/// write. Nothing in Slice 0 enqueues anything.
abstract interface class SyncQueue {
  Future<void> enqueue(QueuedAction action);

  /// Ordered by [QueuedAction.sequence] within each trip.
  Future<List<QueuedAction>> pending({String? tripId});

  Future<List<QueuedAction>> failed();

  Future<void> resolve(String id, ReplayOutcome outcome, {String? message});

  /// Everything for a trip that has closed.
  Future<void> purgeTrip(String tripId);

  Stream<int> get pendingCount;
}
