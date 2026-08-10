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
import '../bloc/inspection_bloc.dart';

/// The photograph for one failed safety-critical checklist item.
///
/// Owns its own [EvidenceCubit]: two failed critical items are two distinct
/// photographs and two distinct ids, and one shared cubit would let the second
/// capture quietly replace the first.
///
/// The id is pushed into the inspection draft as soon as the server issues it,
/// so it survives a kill exactly like the rest of the checklist.
class EvidenceSlot extends StatefulWidget {
  const EvidenceSlot({
    required this.itemCode,
    required this.itemLabel,
    required this.attachedId,
    super.key,
  });

  final String itemCode;
  final String itemLabel;

  /// What the draft already holds, restored from storage on reopen.
  final String? attachedId;

  @override
  State<EvidenceSlot> createState() => _EvidenceSlotState();
}

class _EvidenceSlotState extends State<EvidenceSlot> {
  late final EvidenceCubit _cubit = EvidenceCubit(
    api: EvidenceApi(sl<ApiClient>()),
    capture: sl<PhotoCapture>(),
    permissions: sl<PermissionService>(),
    connectivity: sl<ConnectivityService>(),
    // Derived from where the driver came from, never offered as a choice.
    category: EvidenceCategory.inspectionPhoto,
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
        listener: (context, state) {
          // The moment the server issues an id, the draft cites it. Holding it
          // only in memory would lose it to a kill, and the checklist would
          // reopen still demanding a photograph the driver already took.
          context
              .read<InspectionBloc>()
              .add(ItemEvidenceChanged(widget.itemCode, state.evidenceId));
        },
        builder: (context, state) {
          // The draft is the source of truth for "attached". A restored id with
          // no cubit state behind it still counts.
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
                  // The server's own wording on a refusal.
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
                      : strings.inspectionEvidenceRequired(widget.itemLabel),
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
                    // Only Settings can undo "don't ask again", so offering a
                    // retry that cannot succeed would waste the driver's time.
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
