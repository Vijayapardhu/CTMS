import 'package:flutter/material.dart';

import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/not_built_yet.dart';
import '../../../l10n/app_localizations.dart';

/// R2 — the map tab.
class MapScreen extends StatelessWidget {
  const MapScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(AppStrings.of(context).tabMap)),
      body: const NotBuiltYet(icon: AppIcon.map),
    );
  }
}
