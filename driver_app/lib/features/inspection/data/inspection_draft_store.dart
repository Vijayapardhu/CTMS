import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/services/logger_service.dart';
import '../domain/checklist.dart';

/// Where a part-finished inspection lives between app launches.
///
/// **The draft survives everything** — app kill, phone restart, battery death.
/// Fourteen items entered on a phone in the cold is real work, and a driver who
/// loses it once fills it in less honestly the second time.
///
/// Ordinary preferences rather than secure storage: a checklist is operational
/// data, not a credential, and encrypting it would buy nothing while making it
/// unreadable to a diagnostics screen.
class InspectionDraftStore {
  const InspectionDraftStore(this._prefs, this._logger);

  final SharedPreferences _prefs;
  final LoggerService _logger;

  /// Keyed by bus. A driver reassigned to a different vehicle must not inherit
  /// the odometer and verdicts recorded against the one they were on before.
  static String _keyFor(String busId) => 'inspection.draft.$busId';

  Future<void> save(InspectionDraft draft) async {
    await _prefs.setString(_keyFor(draft.busId), jsonEncode(draft.toJson()));
  }

  InspectionDraft? read(String busId) {
    final raw = _prefs.getString(_keyFor(busId));
    if (raw == null) return null;

    try {
      final decoded = jsonDecode(raw);

      return decoded is Map<String, dynamic>
          ? InspectionDraft.fromJson(decoded)
          : null;
    } on FormatException {
      // A draft written by an older build, or a half-written string from a
      // process killed mid-flush. Losing it is bad; crashing the screen that
      // would let the driver start again is worse.
      _logger.warn('Discarding an unreadable inspection draft');
      return null;
    }
  }

  Future<void> clear(String busId) => _prefs.remove(_keyFor(busId));
}
