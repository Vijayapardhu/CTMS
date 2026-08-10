import 'package:equatable/equatable.dart';

/// How loudly a notification speaks. The server's own word.
enum AlertPriority {
  critical,
  high,
  normal,
  low;

  static AlertPriority parse(Object? raw) => switch (raw) {
        'CRITICAL' => AlertPriority.critical,
        'HIGH' => AlertPriority.high,
        'LOW' => AlertPriority.low,
        _ => AlertPriority.normal,
      };

  bool get isUrgent => this == AlertPriority.critical || this == AlertPriority.high;
}

/// One notification, as `GET /notifications` returns it.
class Alert extends Equatable {
  const Alert({
    required this.id,
    required this.title,
    required this.body,
    required this.category,
    required this.priority,
    required this.createdAt,
    this.readAt,
    this.subjectType,
    this.subjectId,
  });

  final String id;

  /// Both written by the backend for the person reading them. Shown verbatim —
  /// they already carry the bus registration and the urgency.
  final String title;
  final String body;

  final String category;
  final AlertPriority priority;
  final DateTime? createdAt;
  final DateTime? readAt;

  /// What the notification is about. Kept so a later slice can open it; not
  /// used to navigate yet, because the screens it would point at are not all
  /// built and a link that goes nowhere is worse than none.
  final String? subjectType;
  final String? subjectId;

  bool get isUnread => readAt == null;

  static Alert? fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    if (id is! String) return null;

    return Alert(
      id: id,
      title: '${json['title'] ?? ''}',
      body: '${json['body'] ?? ''}',
      category: '${json['category'] ?? ''}',
      priority: AlertPriority.parse(json['priority']),
      createdAt: json['created_at'] is String
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      readAt: json['read_at'] is String
          ? DateTime.tryParse(json['read_at'] as String)
          : null,
      subjectType: json['subject_type'] as String?,
      subjectId: json['subject_id'] as String?,
    );
  }

  Alert asRead(DateTime at) => Alert(
        id: id,
        title: title,
        body: body,
        category: category,
        priority: priority,
        createdAt: createdAt,
        readAt: at,
        subjectType: subjectType,
        subjectId: subjectId,
      );

  @override
  List<Object?> get props => [id, readAt];
}
