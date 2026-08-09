/// Evidence payloads in the documented shape.
///
/// `POST /evidence` returns an **id, never a URL** — `download_path` is carried
/// by the contract but the app never turns it into a link.
///
/// The error envelope lives in `inspection_fixtures.dart`; there is one of it
/// because there is one of it server-side.
library;

Map<String, dynamic> categoriesResponse({
  int maxBytes = 8 * 1024 * 1024,
  List<String>? mimeTypes,
}) {
  return {
    'success': true,
    'message': 'Evidence categories retrieved successfully.',
    'code': 200,
    'data': [
      {
        'category': 'INSPECTION_PHOTO',
        'max_bytes': maxBytes,
        'mime_types':
            mimeTypes ?? const ['image/jpeg', 'image/png', 'image/heic', 'image/webp'],
      },
      {
        'category': 'INCIDENT_PHOTO',
        'max_bytes': maxBytes,
        'mime_types':
            mimeTypes ?? const ['image/jpeg', 'image/png', 'image/heic', 'image/webp'],
      },
    ],
  };
}

Map<String, dynamic> uploadResponse({
  String id = 'evidence-1',
  String category = 'INSPECTION_PHOTO',
}) {
  return {
    'success': true,
    'message': 'Evidence uploaded.',
    'code': 201,
    'data': {
      'id': id,
      'category': category,
      'mime_type': 'image/jpeg',
      'size_bytes': 184320,
      'checksum': 'e3b0c44298fc1c14',
      'download_path': '/api/v1/evidence/$id',
    },
  };
}
