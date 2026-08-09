import 'package:flutter/material.dart';

import '../design_system/tokens.dart';

/// What the skeleton is standing in for.
enum SkeletonShape { card, list, line }

/// Component 17 — `SkeletonLoader`.
///
/// **Used only where the layout is known in advance** — otherwise a spinner.
/// A skeleton promises "what arrives will look like this"; using one for an
/// unknown layout breaks that promise and makes the real content feel like a
/// jump rather than a fill.
class SkeletonLoader extends StatefulWidget {
  const SkeletonLoader({
    this.shape = SkeletonShape.card,
    this.count = 1,
    super.key,
  });

  final SkeletonShape shape;
  final int count;

  @override
  State<SkeletonLoader> createState() => _SkeletonLoaderState();
}

class _SkeletonLoaderState extends State<SkeletonLoader>
    with SingleTickerProviderStateMixin {
  late final AnimationController _shimmer = AnimationController(
    vsync: this,
    duration: Motion.skeletonShimmer,
  );

  @override
  void initState() {
    super.initState();

    // Reduce-motion falls back to a static block rather than a slower shimmer:
    // a driver who asked the system to stop animating meant it.
    if (!_reduceMotion) _shimmer.repeat(reverse: true);
  }

  bool get _reduceMotion =>
      WidgetsBinding.instance.platformDispatcher.accessibilityFeatures.disableAnimations;

  @override
  void dispose() {
    _shimmer.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: MaterialLocalizations.of(context).modalBarrierDismissLabel == ''
          ? 'Loading'
          : 'Loading',
      excludeSemantics: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < widget.count; i++) ...[
            if (i > 0) const SizedBox(height: Spacing.md),
            _block(context),
          ],
        ],
      ),
    );
  }

  Widget _block(BuildContext context) {
    final height = switch (widget.shape) {
      SkeletonShape.card => 140.0,
      SkeletonShape.list => Sizes.touchTarget,
      SkeletonShape.line => 16.0,
    };

    return AnimatedBuilder(
      animation: _shimmer,
      builder: (context, _) {
        final base = Theme.of(context).colorScheme.surfaceContainerHighest;

        return Container(
          height: height,
          decoration: BoxDecoration(
            color: _reduceMotion
                ? base
                : Color.lerp(base, base.withValues(alpha: 0.45), _shimmer.value),
            borderRadius: BorderRadius.circular(Radii.md),
          ),
        );
      },
    );
  }
}
