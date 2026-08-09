import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/api/api_failure.dart';
import '../../../../core/connectivity/connectivity_service.dart';
import '../../../../core/services/permission_service.dart';
import '../../data/evidence_api.dart';
import '../../data/photo_capture.dart';
import '../../domain/evidence.dart';
import '../../domain/evidence_state.dart';

/// M8 — one photograph, from the camera to an id.
///
/// One cubit per attachment point. A checklist with two failed critical items
/// has two of these, because each owns a distinct photograph and a distinct id,
/// and sharing one would let the second capture silently replace the first.
class EvidenceCubit extends Cubit<EvidenceState> {
  EvidenceCubit({
    required EvidenceApi api,
    required PhotoCapture capture,
    required PermissionService permissions,
    required ConnectivityService connectivity,
    required this.category,
  })  : _api = api,
        _capture = capture,
        _permissions = permissions,
        _connectivity = connectivity,
        super(const EvidenceIdle());

  final EvidenceApi _api;
  final PhotoCapture _capture;
  final PermissionService _permissions;
  final ConnectivityService _connectivity;

  /// Fixed at construction, from the caller. Never offered to the driver.
  final EvidenceCategory category;

  EvidenceLimits _limits = EvidenceLimits.fallback;
  bool _limitsLoaded = false;

  /// Opens the camera, asking for permission first if it has not been given.
  Future<void> capture() async {
    final status = await _permissions.status(AppPermission.camera);

    final granted = status == PermissionStatus.granted
        ? status
        : await _permissions.request(AppPermission.camera);

    if (granted != PermissionStatus.granted) {
      emit(EvidenceBlocked(
        permanently: granted == PermissionStatus.permanentlyDenied ||
            granted == PermissionStatus.restricted,
      ));
      return;
    }

    emit(const EvidenceCapturing());

    final photo = await _capture.take();

    if (photo == null) {
      // Backed out of the camera. Whatever was already attached stays; there is
      // nothing to undo.
      emit(state.photo == null ? const EvidenceIdle() : EvidencePreviewing(state.photo!));
      return;
    }

    emit(EvidencePreviewing(photo));
  }

  /// Opens the system settings, for a permission only Settings can restore.
  Future<void> openSettings() => _permissions.openSettings();

  /// The driver confirmed the photograph. **Now** it goes up.
  ///
  /// Deliberately not on capture: an upload that is never cited is swept after
  /// 48 hours, and uploading every discarded frame would spend a driver's
  /// data on photographs nobody will ever look at.
  Future<void> confirm() async {
    final photo = state.photo;
    if (photo == null) return;

    await _ensureLimits();

    // Checked before sending rather than after being refused. The ceiling is
    // the server's, read from /evidence/categories, so this is the same rule
    // and not a second opinion.
    if (photo.sizeBytes > _limits.maxBytes) {
      emit(EvidenceRejected(
        photo,
        ConflictFailure('Too large. Maximum ${_limits.maxMegabytes} MB.'),
      ));
      return;
    }

    // The photograph exists; the id it will receive does not. Anything citing
    // that id cannot be submitted, and saying "attached" here would be a lie
    // about a safety record.
    if (_connectivity.current == Reachability.offline) {
      emit(EvidenceQueued(photo));
      return;
    }

    emit(EvidenceUploading(photo));

    try {
      final record = await _api.upload(
        photo,
        category,
        onProgress: (sent, _) {
          if (isClosed || state is! EvidenceUploading) return;
          emit(EvidenceUploading(photo, sent: sent));
        },
      );

      emit(EvidenceUploaded(photo, record));
    } on NetworkFailure {
      emit(EvidenceQueued(photo));
    } on ApiFailure catch (e) {
      // A 409 here is about the bytes — wrong type, too large. The server's
      // wording is what the driver sees, and the only way forward is a retake:
      // resending the same file would be refused identically.
      emit(EvidenceRejected(photo, e));
    }
  }

  /// Throws the photograph away and starts again.
  Future<void> retake() => capture();

  /// Drops everything, including an id that was already obtained.
  ///
  /// Used when the item this was attached to stops needing it — a driver who
  /// changes a verdict from Fail back to Pass. The id is simply abandoned; the
  /// server sweeps it after 48 hours.
  void clear() => emit(const EvidenceIdle());

  Future<void> _ensureLimits() async {
    if (_limitsLoaded) return;

    try {
      _limits = await _api.limits(category);
    } on ApiFailure {
      // Reference data. Failing to read it must not stop a driver evidencing a
      // brake failure, so the contract's documented default stands in and the
      // server remains the real arbiter.
      _limits = EvidenceLimits.fallback;
    }

    _limitsLoaded = true;
  }
}
