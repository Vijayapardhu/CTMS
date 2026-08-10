import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../app/di/service_locator.dart';
import '../../../../core/api/api_client.dart';
import '../../../../core/connectivity/connectivity_service.dart';
import '../../../../core/design_system/tokens.dart';
import '../../../../core/services/permission_service.dart';
import '../../../../core/widgets/evidence_card.dart';
import '../../../../l10n/app_localizations.dart';
import '../../../evidence/data/evidence_api.dart';
import '../../../evidence/data/photo_capture.dart';
import '../../../evidence/domain/evidence.dart';
import '../../../evidence/domain/evidence_state.dart';
import '../../../evidence/presentation/bloc/evidence_cubit.dart';

/// The photograph an operational incident requires.
///
/// The same subsystem the inspection uses — same cubit, same card, same upload
/// path, same private storage and ownership rules. Only the category differs,
/// and that is derived from where the driver is rather than offered as a
/// choice. Nothing about evidence is reimplemented here.
class IncidentEvidence extends StatefulWidget {
  const IncidentEvidence({
    required this.label,
    required this.attachedId,
    required this.onAttached,
    super.key,
  });

  /// The incident type's own label, so the requirement names what it is for.
  final String label;

  final String? attachedId;

  /// Called with the id the server issued, or null when it is taken away.
  final ValueChanged<String?> onAttached;

  @override
  State<IncidentEvidence> createState() => _IncidentEvidenceState();
}

class _IncidentEvidenceState extends State<IncidentEvidence> {
  late final EvidenceCubit _cubit = EvidenceCubit(
    api: EvidenceApi(sl<ApiClient>()),
    capture: sl<PhotoCapture>(),
    permissions: sl<PermissionService>(),
    connectivity: sl<ConnectivityService>(),
    category: EvidenceCategory.incidentPhoto,
  );

  @override
  void dispose() {
    _cubit.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return BlocProvider<EvidenceCubit>.value(
      value: _cubit,
      child: BlocConsumer<EvidenceCubit, EvidenceState>(
        listenWhen: (before, after) => before.evidenceId != after.evidenceId,
        // The report cites the id the moment the server issues one. Nothing is
        // shown as attached before that.
        listener: (context, state) => widget.onAttached(state.evidenceId),
        builder: (context, state) {
          final attached = widget.attachedId != null;

          final card = switch (state) {
            EvidenceIdle() when attached => EvidenceCardState.uploaded,
            EvidenceIdle() => EvidenceCardState.empty,
            EvidenceBlocked() => EvidenceCardState.blocked,
            EvidenceCapturing() => EvidenceCardState.capturing,
            EvidencePreviewing() => EvidenceCardState.preview,
            EvidenceUploading() => EvidenceCardState.uploading,
            EvidenceUploaded() => EvidenceCardState.uploaded,
            EvidenceQueued() => EvidenceCardState.queued,
            EvidenceRejected() => EvidenceCardState.rejected,
          };

          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              EvidenceCard(
                state: card,
                required: true,
                thumbnail: state.photo == null
                    ? null
                    : Uint8List.fromList(state.photo!.bytes),
                message: switch (state) {
                  EvidenceRejected(:final reason) => reason.message,
                  EvidenceQueued() => strings.evidenceQueuedDetail,
                  EvidenceBlocked(:final reason) => switch (reason) {
                      CameraBlock.denied => strings.evidenceBlockedDetail,
                      CameraBlock.permanentlyDenied =>
                        strings.evidenceBlockedPermanently,
                      CameraBlock.unavailable =>
                        strings.evidenceBlockedUnavailable,
                    },
                  _ => attached
                      ? null
                      : strings.incidentEvidenceRequired(widget.label),
                },
                progress: state is EvidenceUploading ? state.progress : null,
                onCapture: () => _cubit.capture(),
                onConfirm: () => _cubit.confirm(),
                onRetake: () => _cubit.retake(),
              ),
              if (state is EvidenceBlocked && state.settingsCanFix)
                Padding(
                  padding: const EdgeInsets.only(top: Spacing.xs),
                  child: TextButton(
                    onPressed: () => _cubit.openSettings(),
                    child: Text(strings.evidenceOpenSettings),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
