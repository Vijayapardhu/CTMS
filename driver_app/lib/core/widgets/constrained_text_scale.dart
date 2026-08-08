import 'package:flutter/material.dart';

/// Caps text scaling for its subtree.
///
/// Wrap **only** a control with a genuine spatial budget: the boarding counter
/// whose two-digit figure must fit a 96dp button, the SOS label inside its
/// ring, a status pill sized to its row. Everything else — every piece of
/// prose, every list item, every screen title — scales to whatever the driver
/// has set, without limit.
///
/// The distinction matters. A global clamp is easy to write and quietly denies
/// a driver with low vision the setting they chose; a local one protects the
/// handful of controls that genuinely cannot grow, and nothing else.
class ConstrainedTextScale extends StatelessWidget {
  const ConstrainedTextScale({
    required this.child,
    this.maxScaleFactor = 1.3,
    super.key,
  });

  final Widget child;

  /// The ceiling for this subtree. Above 1.3 a two-digit counter stops fitting
  /// its button, and a clipped control is worse than smaller text.
  final double maxScaleFactor;

  @override
  Widget build(BuildContext context) {
    final scaler = MediaQuery.textScalerOf(context).clamp(
      minScaleFactor: 1.0,
      maxScaleFactor: maxScaleFactor,
    );

    return MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: scaler),
      child: child,
    );
  }
}
