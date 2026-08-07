import 'package:flutter/material.dart';

import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/not_built_yet.dart';
import '../../../l10n/app_localizations.dart';

/// R1 — the trip tab. The driver's home.
///
/// Slice 0 renders the shell only; the trip state machine (M1) arrives in a
/// later slice.
class TripScreen extends StatelessWidget {
  const TripScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(AppStrings.of(context).tabTrip)),
      body: const NotBuiltYet(icon: AppIcon.trip),
    );
  }
}
