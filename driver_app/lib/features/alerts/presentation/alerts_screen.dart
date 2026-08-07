import 'package:flutter/material.dart';

import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/not_built_yet.dart';
import '../../../l10n/app_localizations.dart';

/// R3 — the alerts tab.
class AlertsScreen extends StatelessWidget {
  const AlertsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(AppStrings.of(context).tabAlerts)),
      body: const NotBuiltYet(icon: AppIcon.alerts),
    );
  }
}
