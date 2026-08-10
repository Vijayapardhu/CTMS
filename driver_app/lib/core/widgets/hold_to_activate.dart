import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../design_system/ctms_colors.dart';
import '../design_system/tokens.dart';
import '../icons/app_icons.dart';

/// Component 12 — `HoldToActivate`.
///
/// The guard in front of an irreversible alarm. A hold rather than a
/// confirmation dialog, because a dialog during an emergency is one more
/// screen between a driver and help, and because a pocket cannot hold a
/// button down deliberately for a second and a half.
///
/// Releasing early cancels and says nothing further: the driver did not mean
/// it, and scolding them about it wastes the moment.
class HoldToActivate extends StatefulWidget {
  const HoldToActivate({
    required this.label,
    required this.holdLabel,
    required this.onActivated,
    this.duration = const Duration(milliseconds: 1500),
    this.size = 200,
    super.key,
  });

  final String label;

  /// Shown while the hold is in progress.
  final String holdLabel;

  final VoidCallback onActivated;
  final Duration duration;
  final double size;

  @override
  State<HoldToActivate> createState() => _HoldToActivateState();
}

class _HoldToActivateState extends State<HoldToActivate>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: widget.duration,
  )..addStatusListener(_onStatus);

  bool _fired = false;

  void _onStatus(AnimationStatus status) {
    if (status != AnimationStatus.completed || _fired) return;

    _fired = true;
    // Two pulses on completion, distinct from the one that starts the hold, so
    // the driver knows it went without looking at the screen.
    unawaited(HapticFeedback.heavyImpact());
    widget.onActivated();
  }

  void _start() {
    _fired = false;
    unawaited(HapticFeedback.mediumImpact());
    _controller.forward(from: 0);
  }

  void _cancel() {
    if (_fired) return;
    _controller.reverse();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final theme = Theme.of(context);

    return Semantics(
      button: true,
      label: widget.label,
      hint: widget.holdLabel,
      excludeSemantics: true,
      child: GestureDetector(
        onTapDown: (_) => _start(),
        onTapUp: (_) => _cancel(),
        onTapCancel: _cancel,
        child: SizedBox(
          width: widget.size,
          height: widget.size,
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, child) {
              return Stack(
                alignment: Alignment.center,
                children: [
                  // The ring fills as the hold progresses. It is the only
                  // moving thing on the screen, so it cannot be missed.
                  SizedBox.expand(
                    child: CircularProgressIndicator(
                      value: _controller.value,
                      strokeWidth: 10,
                      backgroundColor: colors.critical.withValues(alpha: 0.18),
                      valueColor: AlwaysStoppedAnimation(colors.critical),
                    ),
                  ),
                  Container(
                    width: widget.size - 40,
                    height: widget.size - 40,
                    decoration: BoxDecoration(
                      color: colors.critical,
                      shape: BoxShape.circle,
                    ),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        AppIconView(
                          AppIcon.sos,
                          size: IconSize.sos,
                          color: colors.onCritical,
                        ),
                        const SizedBox(height: Spacing.xs),
                        Text(
                          _controller.isAnimating
                              ? widget.holdLabel
                              : widget.label,
                          textAlign: TextAlign.center,
                          style: theme.textTheme.titleMedium?.copyWith(
                            color: colors.onCritical,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}
