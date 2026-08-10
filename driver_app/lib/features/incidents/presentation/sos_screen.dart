import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/sos/sos_service.dart';
import '../../../core/widgets/hold_to_activate.dart';
import '../../../l10n/app_localizations.dart';
import '../../gps/presentation/bloc/gps_cubit.dart';
import '../../trip/presentation/bloc/trip_bloc.dart';

/// P17 — the emergency screen.
///
/// Deliberately reachable with no trip, no fix and no network. It reads the
/// trip and the position if they happen to exist, and attaches them; it never
/// waits for either. The one control is the alarm.
class SosScreen extends StatelessWidget {
  const SosScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(strings.sosTitle)),
      body: BlocBuilder<SosService, SosState>(
        builder: (context, state) => SafeArea(
          minimum: const EdgeInsets.all(Spacing.lg),
          child: switch (state) {
            SosIdle() || SosSending() => _Raise(sending: state is SosSending),
            SosSent(:final message) => _Sent(message: message),
            SosQueued() => _Queued(state),
            SosRefused(:final message) => _Refused(message),
          },
        ),
      ),
    );
  }
}

/// The alarm itself, and nothing else.
class _Raise extends StatelessWidget {
  const _Raise({required this.sending});

  final bool sending;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          strings.sosPrompt,
          textAlign: TextAlign.center,
          style: theme.textTheme.titleLarge,
        ),
        const SizedBox(height: Spacing.xxl),
        if (sending)
          const SizedBox.square(dimension: 200, child: Center(child: CircularProgressIndicator()))
        else
          HoldToActivate(
            label: strings.sosHold,
            holdLabel: strings.sosHolding,
            onActivated: () => _raise(context),
          ),
        const SizedBox(height: Spacing.xxl),
        Text(
          strings.sosNoDetailsNeeded,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
        ),
      ],
    );
  }

  /// Takes whatever context exists and raises the alarm with it. Nothing here
  /// is awaited before the alert goes: a trip that has not loaded and a fix
  /// that has not arrived are both fine.
  void _raise(BuildContext context) {
    final trip = context.read<TripBloc>().state.trip;
    final fix = context.read<GpsCubit>().state.lastFix;

    context.read<SosService>().raise(
          tripId: trip?.id,
          latitude: fix?.latitude,
          longitude: fix?.longitude,
        );
  }
}

/// The server has it.
class _Sent extends StatelessWidget {
  const _Sent({this.message});

  final String? message;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppIconView(AppIcon.success, size: IconSize.sos, color: colors.positive),
        const SizedBox(height: Spacing.lg),
        Text(
          strings.sosSentTitle,
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineSmall?.copyWith(color: colors.positive),
        ),
        const SizedBox(height: Spacing.sm),
        Text(
          // The server's own words where it gave any.
          message ?? strings.sosSentBody,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyLarge,
        ),
        const SizedBox(height: Spacing.xl),
        const _NativeFallback(),
      ],
    );
  }
}

/// Written down, not sent. The distinction this whole screen turns on.
class _Queued extends StatelessWidget {
  const _Queued(this.state);

  final SosQueued state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppIconView(AppIcon.warning, size: IconSize.sos, color: colors.caution),
        const SizedBox(height: Spacing.lg),
        Text(
          strings.sosQueuedTitle,
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineSmall?.copyWith(color: colors.caution),
        ),
        const SizedBox(height: Spacing.sm),
        Text(
          strings.sosQueuedBody,
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyLarge,
        ),
        const SizedBox(height: Spacing.xl),
        // The point of this screen: with no signal, the phone in their hand is
        // still a phone.
        _NativeFallback(
          latitude: state.latitude,
          longitude: state.longitude,
          urgent: true,
        ),
      ],
    );
  }
}

class _Refused extends StatelessWidget {
  const _Refused(this.message);

  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final colors = context.ctms;

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppIconView(AppIcon.error, size: IconSize.sos, color: colors.critical),
        const SizedBox(height: Spacing.lg),
        // Verbatim. A refusal of an emergency alert is not something to
        // rephrase.
        Text(
          message,
          textAlign: TextAlign.center,
          style: theme.textTheme.titleMedium?.copyWith(color: colors.critical),
        ),
        const SizedBox(height: Spacing.xl),
        const _NativeFallback(urgent: true),
      ],
    );
  }
}

/// Call and text, which work when nothing else does.
class _NativeFallback extends StatelessWidget {
  const _NativeFallback({this.latitude, this.longitude, this.urgent = false});

  final double? latitude;
  final double? longitude;

  /// Renders the call as the primary action rather than a quiet alternative.
  final bool urgent;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final sos = context.read<SosService>();

    // No number, no pretence. Saying so beats a button that opens an empty
    // dialler in an emergency.
    if (!sos.contact.isConfigured) {
      return Text(
        strings.sosNoNumber,
        textAlign: TextAlign.center,
        style: theme.textTheme.bodyMedium
            ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          height: Sizes.buttonProminent,
          child: urgent
              ? FilledButton.icon(
                  onPressed: sos.call,
                  icon: const AppIconView(AppIcon.sos, size: IconSize.md),
                  label: Text(strings.sosCall),
                )
              : OutlinedButton.icon(
                  onPressed: sos.call,
                  icon: const AppIconView(AppIcon.sos, size: IconSize.md),
                  label: Text(strings.sosCall),
                ),
        ),
        const SizedBox(height: Spacing.sm),
        SizedBox(
          height: Sizes.buttonHeight,
          child: OutlinedButton(
            onPressed: () => sos.sendSms(latitude: latitude, longitude: longitude),
            child: Text(strings.sosSms),
          ),
        ),
      ],
    );
  }
}
