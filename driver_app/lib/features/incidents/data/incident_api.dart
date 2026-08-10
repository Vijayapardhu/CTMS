import '../../../core/api/api_client.dart';
import '../domain/incident.dart';

/// The incident endpoints.
class IncidentApi {
  const IncidentApi(this._client);

  final ApiClient _client;

  /// `GET /incidents/types`
  ///
  /// The list, its labels, and the rules that go with it. Read from the server
  /// so a type added or a photograph rule changed there needs no app release.
  Future<List<IncidentType>> types() async {
    final body = await _client.get('/incidents/types');
    final data = body['data'];

    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(IncidentType.fromJson)
        .whereType<IncidentType>()
        .toList(growable: false);
  }

  /// `POST /incidents`
  ///
  /// Returns the raw envelope so the sync engine can replay this call
  /// unchanged when a report was queued.
  Future<Map<String, dynamic>> report(IncidentReport report) {
    return _client.post('/incidents', body: report.toJson());
  }
}
