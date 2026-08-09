/// What a photograph is being taken *for*.
///
/// **Never a driver's choice.** The category is determined by where they came
/// from, because citing the wrong one is a 409 that says nothing a driver can
/// act on. A picker here would be a way to fail.
enum EvidenceCategory {
  inspectionPhoto('INSPECTION_PHOTO'),
  incidentPhoto('INCIDENT_PHOTO');

  const EvidenceCategory(this.wire);

  /// The value the API expects. UPPERCASE and canonical.
  final String wire;
}

/// The server's limits for a category, from `GET /evidence/categories`.
///
/// Read rather than assumed: the ceiling is the server's to set, and a client
/// that hard-codes 8 MB will reject a photograph the backend would have taken
/// the day operations raises it.
class EvidenceLimits {
  const EvidenceLimits({
    required this.maxBytes,
    required this.acceptedMimeTypes,
  });

  final int maxBytes;
  final List<String> acceptedMimeTypes;

  /// Used only when the server has not been asked yet — the contract's own
  /// documented default, not a number invented here.
  static const fallback = EvidenceLimits(
    maxBytes: 8 * 1024 * 1024,
    acceptedMimeTypes: ['image/jpeg', 'image/png', 'image/heic', 'image/webp'],
  );

  int get maxMegabytes => (maxBytes / (1024 * 1024)).round();

  /// Picks the entry for [category] out of the categories response.
  ///
  /// The shape is read defensively because this is reference data the app
  /// caches: a field renamed server-side should degrade to the documented
  /// default rather than stop a driver evidencing a brake failure.
  static EvidenceLimits forCategory(
    Object? payload,
    EvidenceCategory category,
  ) {
    if (payload is! List) return fallback;

    for (final entry in payload.whereType<Map<String, dynamic>>()) {
      if (entry['category'] != category.wire) continue;

      final types = entry['mime_types'] ?? entry['accepted_mime_types'];
      final max = entry['max_bytes'] ?? entry['max_size_bytes'];

      return EvidenceLimits(
        maxBytes: max is int ? max : fallback.maxBytes,
        acceptedMimeTypes: types is List
            ? types.map((t) => '$t').toList(growable: false)
            : fallback.acceptedMimeTypes,
      );
    }

    return fallback;
  }
}

/// A photograph taken but not yet sent.
///
/// Holds bytes rather than a path: the file the camera wrote is temporary, and
/// a queued photograph has to survive the app that captured it.
class CapturedPhoto {
  const CapturedPhoto({
    required this.bytes,
    required this.mimeType,
    required this.fileName,
  });

  final List<int> bytes;
  final String mimeType;

  /// Generated, never the camera's own name. A predictable filename is a
  /// filename someone else can guess.
  final String fileName;

  int get sizeBytes => bytes.length;
}

/// The server's record. An **id, never a URL**.
///
/// `download_path` is carried but never turned into a link by the app: the
/// endpoint is authorised, and building a URL by hand is how an unauthorised
/// one gets built.
class UploadedEvidence {
  const UploadedEvidence({
    required this.id,
    required this.mimeType,
    required this.sizeBytes,
  });

  final String id;
  final String mimeType;
  final int sizeBytes;

  static UploadedEvidence fromJson(Map<String, dynamic> json) {
    return UploadedEvidence(
      id: json['id'] as String? ?? '',
      mimeType: json['mime_type'] as String? ?? '',
      sizeBytes: json['size_bytes'] as int? ?? 0,
    );
  }
}
