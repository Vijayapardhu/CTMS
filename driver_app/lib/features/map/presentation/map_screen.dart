import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../../core/design_system/ctms_colors.dart';
import '../../../core/design_system/tokens.dart';
import '../../../core/icons/app_icons.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../l10n/app_localizations.dart';
import '../../gps/domain/gps_state.dart';
import '../../gps/presentation/bloc/gps_cubit.dart';
import '../../gps/presentation/widgets/gps_status_pill.dart';

/// R2 — the map.
///
/// Draws the one position this app can honestly claim to know: the driver's
/// own last fix, from M3. It does not ask the server where the bus is — the
/// phone in the cradle *is* where the bus is, and a round trip would only add
/// latency to a fact already in hand.
///
/// The route line, the next-stop sheet and SOS in the wireframe need stop data
/// and an alert flow that arrive with their own slices. Rendering them from
/// nothing would be inventing a route.
class MapScreen extends StatefulWidget {
  const MapScreen({super.key});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  final _controller = Completer<GoogleMapController>();

  /// Off once the driver pans. A camera that snaps back every five seconds
  /// makes the map unusable for looking slightly ahead, which is the only
  /// reason a driver opens it mid-run.
  bool _following = true;

  /// Somewhere to point the camera before the first fix arrives.
  static const _initial = CameraPosition(
    target: LatLng(12.9716, 77.5946),
    zoom: 11,
  );

  Future<void> _recentre(PositionFix fix) async {
    setState(() => _following = true);
    final map = await _controller.future;
    await map.animateCamera(
      CameraUpdate.newLatLngZoom(LatLng(fix.latitude, fix.longitude), 16),
    );
  }

  void _followIfAllowed(PositionFix? fix) {
    if (!_following || fix == null || !_controller.isCompleted) return;

    unawaited(_controller.future.then((map) => map.animateCamera(
          CameraUpdate.newLatLng(LatLng(fix.latitude, fix.longitude)),
        )));
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(strings.tabMap)),
      body: BlocConsumer<GpsCubit, GpsState>(
        listener: (context, state) => _followIfAllowed(state.lastFix),
        builder: (context, state) {
          // Nothing to draw and nothing to pretend. A map centred on a default
          // city with no marker looks like a bus parked in the wrong place.
          if (state is GpsIdle) {
            return EmptyState(
              icon: AppIcon.map,
              title: strings.mapIdleTitle,
              body: strings.mapIdleBody,
            );
          }

          final fix = state.lastFix;
          final stale = state is GpsNoSignal || state is GpsBuffering;

          return Stack(
            children: [
              GoogleMap(
                initialCameraPosition: fix == null
                    ? _initial
                    : CameraPosition(
                        target: LatLng(fix.latitude, fix.longitude),
                        zoom: 16,
                      ),
                onMapCreated: (controller) {
                  if (!_controller.isCompleted) _controller.complete(controller);
                },
                // The blue dot is the *phone*, which is the bus. Shown so the
                // driver can sanity-check the marker against what the platform
                // itself believes.
                myLocationEnabled: true,
                myLocationButtonEnabled: false,
                compassEnabled: true,
                zoomControlsEnabled: false,
                markers: {
                  if (fix != null)
                    Marker(
                      markerId: const MarkerId('bus'),
                      position: LatLng(fix.latitude, fix.longitude),
                      rotation: fix.heading ?? 0,
                      flat: true,
                      // Faded when the position is not current, per R2. A
                      // fresh-looking marker over an old fix is the lie this
                      // screen most easily tells.
                      alpha: stale ? 0.45 : 1.0,
                      infoWindow: InfoWindow(title: strings.mapBusMarker),
                    ),
                },
                onCameraMoveStarted: () {
                  if (_following) setState(() => _following = false);
                },
              ),
              Positioned(
                left: Spacing.md,
                top: Spacing.md,
                child: GpsStatusPill(state: state),
              ),
              if (stale && fix != null)
                Positioned(
                  left: Spacing.md,
                  right: Spacing.md,
                  bottom: Spacing.md,
                  child: _StaleBadge(fix.recordedAt),
                ),
              if (fix != null && !_following)
                Positioned(
                  right: Spacing.md,
                  bottom: Spacing.xxl,
                  child: FloatingActionButton(
                    onPressed: () => _recentre(fix),
                    tooltip: strings.mapRecentre,
                    child: const AppIconView(AppIcon.gpsLive),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}

/// How old the marker is, in words. R2 is explicit that a stale position is
/// never presented as a fresh one.
class _StaleBadge extends StatelessWidget {
  const _StaleBadge(this.recordedAt);

  final DateTime recordedAt;

  @override
  Widget build(BuildContext context) {
    final colors = context.ctms;
    final minutes = DateTime.now().toUtc().difference(recordedAt.toUtc()).inMinutes;

    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: Spacing.md,
        vertical: Spacing.sm,
      ),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(Radii.full),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          AppIconView(AppIcon.warning, size: IconSize.sm, color: colors.caution),
          const SizedBox(width: Spacing.sm),
          Flexible(
            child: Text(
              AppStrings.of(context).mapPositionAge(minutes < 1 ? 1 : minutes),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }
}
