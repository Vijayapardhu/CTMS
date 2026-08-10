import '../../../core/api/api_failure.dart';
import 'evidence.dart';

/// M8 — evidence capture and upload.
///
/// ```
///  idle ──► permission ──denied──► blocked
///             │
///           granted
///             ▼
///         capturing ──► previewing ──retake──► capturing
///                           │
///                          use
///                           ▼
///                       uploading
///              ┌────────────┼────────────┐
///              ▼            ▼            ▼
///          uploaded     rejected      queued
///          (has id)   (mime/size)   (offline)
/// ```
sealed class EvidenceState {
  const EvidenceState();

  /// The photograph in hand, once there is one.
  CapturedPhoto? get photo => null;

  /// The server's id, once it has one. Only [EvidenceUploaded] ever has it.
  String? get evidenceId => null;
}

/// Nothing captured, nothing asked for.
final class EvidenceIdle extends EvidenceState {
  const EvidenceIdle();
}

/// The camera was refused.
///
/// Blocking, not degraded: a failing safety-critical inspection cannot be
/// completed without a photograph, and the screen has to say so plainly rather
/// than leave the driver tapping a button that does nothing.
final class EvidenceBlocked extends EvidenceState {
  const EvidenceBlocked({required this.reason});

  final CameraBlock reason;

  /// Only "don't ask again" is fixable from Settings. Offering that button for
  /// the other two would send a driver on an errand that cannot succeed.
  bool get settingsCanFix => reason == CameraBlock.permanentlyDenied;
}

/// Why the camera is not available, kept apart because the three cases need
/// three different things said and two of them need no button at all.
enum CameraBlock {
  /// Refused this time. Asking again is allowed, and tapping the button again
  /// is the driver doing exactly that.
  denied,

  /// "Don't ask again". The OS will not show the dialog any more.
  permanentlyDenied,

  /// No camera, or a work profile that forbids it. Nothing the driver can do
  /// on this handset, so nothing is offered.
  unavailable,
}

/// The camera is open.
final class EvidenceCapturing extends EvidenceState {
  const EvidenceCapturing();
}

/// A photograph is in hand and the driver is deciding.
///
/// Nothing has been uploaded yet. **Upload late, not early**: a file that is
/// never attached is swept after 48 hours, so the bytes go up when the driver
/// confirms, not when the shutter closes.
final class EvidencePreviewing extends EvidenceState {
  const EvidencePreviewing(this.value);

  final CapturedPhoto value;

  @override
  CapturedPhoto? get photo => value;
}

/// Sending. Shows progress and can be abandoned by retaking.
final class EvidenceUploading extends EvidenceState {
  const EvidenceUploading(this.value, {this.sent = 0});

  final CapturedPhoto value;

  /// Bytes acknowledged so far, for the progress bar.
  final int sent;

  double get progress => value.sizeBytes == 0 ? 0 : sent / value.sizeBytes;

  @override
  CapturedPhoto? get photo => value;
}

/// The server took it and gave back an id.
final class EvidenceUploaded extends EvidenceState {
  const EvidenceUploaded(this.value, this.record);

  final CapturedPhoto value;
  final UploadedEvidence record;

  @override
  CapturedPhoto? get photo => value;

  @override
  String? get evidenceId => record.id;
}

/// The server refused the file itself.
///
/// A 409 on this endpoint is about the bytes — wrong type, too large — and the
/// server's own wording is what the driver sees. Retaking is the only way
/// forward; retrying the same file would be refused identically.
final class EvidenceRejected extends EvidenceState {
  const EvidenceRejected(this.value, this.reason);

  final CapturedPhoto value;
  final ApiFailure reason;

  @override
  CapturedPhoto? get photo => value;
}

/// Captured, held, and not uploaded — there was no network.
///
/// The photograph exists; the id does not. Anything that would cite the id
/// cannot be submitted yet, and the screen says so rather than implying the
/// attachment is done.
final class EvidenceQueued extends EvidenceState {
  const EvidenceQueued(this.value);

  final CapturedPhoto value;

  @override
  CapturedPhoto? get photo => value;
}
