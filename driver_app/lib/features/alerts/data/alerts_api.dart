import '../../../core/api/api_client.dart';
import '../domain/alert.dart';

/// The notification endpoints the driver app reads.
class AlertsApi {
  const AlertsApi(this._client);

  final ApiClient _client;

  /// `GET /notifications`
  Future<List<Alert>> list() async {
    final body = await _client.get('/notifications');
    final data = body['data'];

    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(Alert.fromJson)
        .whereType<Alert>()
        .toList(growable: false);
  }

  /// `GET /notifications/unread-count` — deliberately cheap, drives the badge.
  Future<int> unreadCount() async {
    final body = await _client.get('/notifications/unread-count');
    final data = body['data'];

    if (data is! Map<String, dynamic>) return 0;

    final unread = data['unread'];
    return unread is num ? unread.toInt() : 0;
  }

  /// `PATCH /notifications/{id}/read`
  Future<void> markRead(String id) => _client.patch('/notifications/$id/read');

  /// `POST /notifications/read-all`
  Future<void> markAllRead() => _client.post('/notifications/read-all');
}
