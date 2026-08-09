import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../l10n/app_localizations.dart';
import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// Where a photograph has got to.
enum EvidenceCardState {
  empty,
  capturing,
  preview,
  uploading,
  uploaded,
  queued,
  rejected,
  blocked,
}

/// Component 10 — `EvidenceCard`.
///
/// The one thing this must never do is show an attachment as server-confirmed
/// before the server has confirmed it. `queued` therefore has its own look: the
/// photograph exists, the id does not, and the difference matters because a
/// safety record cites the id.
class EvidenceCard extends StatelessWidget {
  const EvidenceCard({
    required this.state,
    required this.required,
    this.thumbnail,
    this.message,
    this.onCapture,
    this.onConfirm,
    this.onRetake,
    this.progress,
    super.key,
  });

  final EvidenceCardState state;

  /// Whether the parent cannot be submitted without this.
  final bool required;

  /// The captured bytes, once there are some.
  final Uint8List? thumbnail;

  /// The server's own words on a refusal, shown verbatim.
  final String? message;

  final VoidCallback? onCapture;
  final VoidCallback? onConfirm;
  final VoidCallback? onRetake;

  final double? progress;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final theme = Theme.of(context);

    final strings = AppStrings.of(context);

    final (tone, icon, label) = switch (state) {
      EvidenceCardState.empty => (
          required ? colors.critical : colors.neutral,
          AppIcon.camera,
          strings.evidenceEmpty,
        ),
      EvidenceCardState.capturing =>
        (colors.neutral, AppIcon.camera, strings.evidenceCapturing),
      EvidenceCardState.preview =>
        (colors.caution, AppIcon.evidence, strings.evidencePreview),
      EvidenceCardState.uploading =>
        (colors.info, AppIcon.upload, strings.evidenceUploading),
      EvidenceCardState.uploaded =>
        (colors.positive, AppIcon.success, strings.evidenceUploaded),
      EvidenceCardState.queued =>
        (colors.caution, AppIcon.offline, strings.evidenceQueued),
      EvidenceCardState.rejected =>
        (colors.critical, AppIcon.error, strings.evidenceRejected),
      EvidenceCardState.blocked =>
        (colors.critical, AppIcon.blocked, strings.evidenceBlocked),
    };

    return Semantics(
      container: true,
      label: message == null ? label : '$label. $message',
      excludeSemantics: true,
      child: Container(
        margin: const EdgeInsets.only(top: Spacing.sm),
        padding: const EdgeInsets.all(Spacing.md),
        decoration: BoxDecoration(
          color: tone.withValues(alpha: 0.10),
          borderRadius: BorderRadius.circular(Radii.sm),
          border: Border.all(color: tone),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Colour never alone: the glyph says empty from uploaded from
                // queued on its own.
                AppIconView(icon, size: IconSize.sm, color: tone),
                const SizedBox(width: Spacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        label,
                        style: theme.textTheme.bodyMedium?.copyWith(color: tone),
                      ),
                      if (message != null)
                        Text(message!, style: theme.textTheme.bodySmall),
                    ],
                  ),
                ),
                if (thumbnail != null) ...[
                  const SizedBox(width: Spacing.sm),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(Radii.sm),
                    child: Image.memory(
                      thumbnail!,
                      width: 56,
                      height: 56,
                      fit: BoxFit.cover,
                      gaplessPlayback: true,
                      // A thumbnail that will not decode is a preview problem,
                      // not a reason to take the card down: the bytes may
                      // still be exactly what the server wants, and it is the
                      // server's job to say.
                      errorBuilder: (context, _, __) => SizedBox.square(
                        dimension: 56,
                        child: AppIconView(AppIcon.evidence, color: tone),
                      ),
                    ),
                  ),
                ],
              ],
            ),
            if (state == EvidenceCardState.uploading) ...[
              const SizedBox(height: Spacing.sm),
              LinearProgressIndicator(value: progress),
            ],
            if (_actions(context).isNotEmpty) ...[
              const SizedBox(height: Spacing.sm),
              Row(children: _actions(context)),
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _actions(BuildContext context) {
    final labels = AppStrings.of(context);

    return switch (state) {
      EvidenceCardState.empty || EvidenceCardState.blocked => [
          if (onCapture != null)
            Expanded(
              child: OutlinedButton(
                onPressed: onCapture,
                child: Text(labels.evidenceTake),
              ),
            ),
        ],
      EvidenceCardState.preview => [
          if (onRetake != null)
            Expanded(
              child: OutlinedButton(
                onPressed: onRetake,
                child: Text(labels.evidenceRetake),
              ),
            ),
          if (onRetake != null && onConfirm != null)
            const SizedBox(width: Spacing.sm),
          if (onConfirm != null)
            Expanded(
              child: FilledButton(
                onPressed: onConfirm,
                child: Text(labels.evidenceUse),
              ),
            ),
        ],
      EvidenceCardState.rejected || EvidenceCardState.queued => [
          if (onRetake != null)
            Expanded(
              child: OutlinedButton(
                onPressed: onRetake,
                child: Text(labels.evidenceRetake),
              ),
            ),
        ],
      _ => const [],
    };
  }
}
