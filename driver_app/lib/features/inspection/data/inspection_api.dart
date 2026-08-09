import '../../../core/api/api_client.dart';
import '../domain/checklist.dart';

/// The two inspection endpoints Slice 4 uses.
class InspectionApi {
  const InspectionApi(this._client);

  final ApiClient _client;

  /// `GET /inspections/checklist`
  ///
  /// Server-driven. Returns the items in the order the server sends them —
  /// re-sorting would put the brakes somewhere other than where the depot's
  /// printed card has them.
  Future<List<ChecklistItem>> checklist() async {
    final body = await _client.get('/inspections/checklist');
    final data = body['data'];

    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(ChecklistItem.fromJson)
        .toList(growable: false);
  }

  /// `POST /buses/{busId}/inspections`
  ///
  /// The outcome comes back in the 201 and is the server's to decide.
  Future<InspectionResult> submit(
    String busId,
    Map<String, dynamic> submission,
  ) async {
    final body = await _client.post('/buses/$busId/inspections', body: submission);
    final data = body['data'];

    return InspectionResult.fromJson(
      data is Map<String, dynamic> ? data : const {},
    );
  }
}
