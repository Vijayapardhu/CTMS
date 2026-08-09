import 'package:dio/dio.dart';
import 'package:http_parser/http_parser.dart';

import '../../../core/api/api_client.dart';
import '../domain/evidence.dart';

/// The evidence endpoints.
///
/// Goes through the same [ApiClient] as everything else, so the bearer, the
/// envelope decoding, the retry policy and the reachability reporting are the
/// ones already tested rather than a second stack built for files.
class EvidenceApi {
  const EvidenceApi(this._client);

  final ApiClient _client;

  /// `GET /evidence/categories` — limits and accepted types, per category.
  Future<EvidenceLimits> limits(EvidenceCategory category) async {
    final body = await _client.get('/evidence/categories');

    return EvidenceLimits.forCategory(body['data'], category);
  }

  /// `POST /evidence` — multipart `file` + `category`.
  ///
  /// The declared content type is the app's best statement of what it sent;
  /// the server sniffs the bytes regardless, and a mismatch is its 409 to
  /// make. Nothing here tries to be believed.
  Future<UploadedEvidence> upload(
    CapturedPhoto photo,
    EvidenceCategory category, {
    void Function(int sent, int total)? onProgress,
  }) async {
    final form = FormData.fromMap({
      'category': category.wire,
      'file': MultipartFile.fromBytes(
        photo.bytes,
        filename: photo.fileName,
        contentType: MediaType.parse(photo.mimeType),
      ),
    });

    final body = await _client.post(
      '/evidence',
      body: form,
      onSendProgress: onProgress,
    );

    final data = body['data'];

    return UploadedEvidence.fromJson(
      data is Map<String, dynamic> ? data : const {},
    );
  }
}
