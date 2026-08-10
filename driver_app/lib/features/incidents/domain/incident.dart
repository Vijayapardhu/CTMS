import 'package:equatable/equatable.dart';

/// What kind of problem this is, in the backend's own terms.
///
/// Never hard-coded. `GET /incidents/types` is the list, and it carries the
/// rules with it — which class a type belongs to, how severe it is by default,
/// and whether a photograph is required. Duplicating any of that here would
/// mean two answers to a safety question.
class IncidentType extends Equatable {
  const IncidentType({
    required this.type,
    required this.label,
    required this.classification,
    required this.classLabel,
    required this.defaultSeverity,
    required this.requiresPhoto,
  });

  final String type;
  final String label;

  /// `LIFE_SAFETY`, `OPERATIONAL`, `SERVICE`.
  final String classification;
  final String classLabel;

  final String defaultSeverity;

  /// The server refuses the report without an `evidence_id` when this is true.
  /// It is what the workshop works from, and what justifies grounding a bus.
  final bool requiresPhoto;

  /// Life safety is the class the SOS control raises, and the one the server
  /// lets through without a description: the type alone is enough to act on,
  /// and a driver in that situation is not typing.
  bool get isLifeSafety => classification == 'LIFE_SAFETY';

  /// The server makes the description optional only for life safety.
  bool get requiresDescription => !isLifeSafety;

  static IncidentType? fromJson(Map<String, dynamic> json) {
    final type = json['type'];
    if (type is! String) return null;

    return IncidentType(
      type: type,
      label: '${json['label'] ?? type}',
      classification: '${json['class'] ?? ''}',
      classLabel: '${json['class_label'] ?? ''}',
      defaultSeverity: '${json['default_severity'] ?? ''}',
      requiresPhoto: json['requires_photo'] == true,
    );
  }

  @override
  List<Object?> get props => [type, requiresPhoto, classification];
}

/// One report, on its way to the server.
class IncidentReport extends Equatable {
  const IncidentReport({
    required this.type,
    required this.idempotencyKey,
    required this.reportedAt,
    this.description,
    this.tripId,
    this.latitude,
    this.longitude,
    this.evidenceId,
    this.vehicleCanContinue,
  });

  final String type;

  /// Minted once, when the report is created. Every retry of this report
  /// carries the same one — a key regenerated per attempt turns one breakdown
  /// into a queue of them, and an SOS into several.
  final String idempotencyKey;

  /// When it happened, not when it was sent. The server honours this, so a
  /// report queued through a tunnel does not look like it happened at the
  /// moment the signal returned.
  final DateTime reportedAt;

  final String? description;
  final String? tripId;
  final double? latitude;
  final double? longitude;
  final String? evidenceId;
  final bool? vehicleCanContinue;

  Map<String, Object?> toJson() => {
        'incident_type': type,
        if (description != null && description!.isNotEmpty)
          'description': description,
        if (tripId != null) 'trip_id': tripId,
        if (latitude != null) 'latitude': latitude,
        if (longitude != null) 'longitude': longitude,
        if (evidenceId != null) 'evidence_id': evidenceId,
        if (vehicleCanContinue != null)
          'vehicle_can_continue': vehicleCanContinue,
        'reported_at': reportedAt.toUtc().toIso8601String(),
        'idempotency_key': idempotencyKey,
      };

  @override
  List<Object?> get props => [type, idempotencyKey];
}

/// What the server did with a report.
class IncidentOutcome {
  const IncidentOutcome({
    required this.id,
    this.status,
    this.severity,
    this.busStatus,
    this.maintenanceTicketId,
    this.message,
  });

  final String id;
  final String? status;
  final String? severity;

  /// Set when the report took the vehicle off the road. Rendered from the
  /// server's own words — the app never decides that a bus is grounded.
  final String? busStatus;
  final String? maintenanceTicketId;

  final String? message;

  bool get groundedVehicle =>
      maintenanceTicketId != null ||
      (busStatus != null && busStatus != 'AVAILABLE' && busStatus != 'RUNNING');

  static IncidentOutcome fromEnvelope(Map<String, dynamic> body) {
    final data = body['data'];
    final map = data is Map<String, dynamic> ? data : const <String, dynamic>{};
    final bus = map['bus'];

    return IncidentOutcome(
      id: '${map['id'] ?? ''}',
      status: map['status'] as String?,
      severity: map['severity'] as String?,
      busStatus: bus is Map<String, dynamic> ? bus['status'] as String? : null,
      maintenanceTicketId: map['maintenance_ticket_id'] as String?,
      message: body['message'] as String?,
    );
  }
}
