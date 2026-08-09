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
import '../../tracking/domain/live_trip.dart';
import '../../tracking/presentation/bloc/tracking_bloc.dart';
import '../../trip/domain/trip_state.dart';
import '../../trip/presentation/bloc/trip_bloc.dart';
import 'widgets/map_styles.dart';
import 'widgets/next_stop_sheet.dart';

/// R2 — the live map.
///
/// Renders state and owns none. The bus comes from the server's live trip, the
/// route and stops from CTMS, the estimate from the backend's Route Matrix.
/// Nothing on this screen is computed from a straight line and an assumed
/// speed, and nothing here talks to Google except the tile surface itself.
class MapScreen extends StatefulWidget {
  const MapScreen({super.key});

  @override
  State<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends State<MapScreen> {
  final _controller = Completer<GoogleMapController>();

  /// Off the moment the driver pans. A camera that snaps back every ten
  /// seconds cannot be used to look at the road ahead, which is the only
  /// reason to open this screen while moving.
  bool _following = true;

  /// True once the platform has handed back a controller. Until then the tile
  /// surface may be blank because the SDK could not authorise — a different
  /// failure from having no bus position, and reported differently.
  bool _mapReady = false;
  Timer? _mapWatchdog;

  bool _fitted = false;

  static const _fallbackCamera = CameraPosition(
    target: LatLng(12.9716, 77.5946),
    zoom: 11,
  );

  @override
  void initState() {
    super.initState();
    // If the SDK cannot authorise — no key, wrong fingerprint, API not enabled
    // — `onMapCreated` never fires and the driver is left looking at a grey
    // rectangle. This turns that silence into a stated failure.
    _mapWatchdog = Timer(const Duration(seconds: 8), () {
      if (mounted && !_mapReady) setState(() {});
    });
  }

  @override
  void dispose() {
    _mapWatchdog?.cancel();
    super.dispose();
  }

  Future<void> _recentre(LatLng target) async {
    setState(() => _following = true);
    final map = await _controller.future;
    await map.animateCamera(CameraUpdate.newLatLngZoom(target, 16));
  }

  void _follow(LatLng? target) {
    if (!_following || target == null || !_controller.isCompleted) return;

    unawaited(_controller.future
        .then((map) => map.animateCamera(CameraUpdate.newLatLng(target))));
  }

  /// Frames the whole route once, so a driver opening the map sees the shape of
  /// the run rather than a rooftop.
  Future<void> _fitRoute(List<RouteStop> stops) async {
    if (_fitted || stops.length < 2 || !_controller.isCompleted) return;
    _fitted = true;

    final lats = stops.map((s) => s.latitude);
    final lngs = stops.map((s) => s.longitude);
    final map = await _controller.future;

    await map.animateCamera(CameraUpdate.newLatLngBounds(
      LatLngBounds(
        southwest: LatLng(lats.reduce((a, b) => a < b ? a : b),
            lngs.reduce((a, b) => a < b ? a : b)),
        northeast: LatLng(lats.reduce((a, b) => a > b ? a : b),
            lngs.reduce((a, b) => a > b ? a : b)),
      ),
      64,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);

    return BlocBuilder<TripBloc, TripState>(
      builder: (context, tripState) {
        // Nothing to track and nothing to pretend. A map centred on a default
        // city with no bus on it reads as a bus parked somewhere it is not.
        if (tripState is! TripRunning) {
          return Scaffold(
            appBar: AppBar(title: Text(strings.tabMap)),
            body: EmptyState(
              icon: AppIcon.map,
              title: strings.mapIdleTitle,
              body: strings.mapIdleBody,
            ),
          );
        }

        return BlocConsumer<TrackingBloc, TrackingState>(
          listener: (context, state) {
            _follow(_busTarget(context, state));
            unawaited(_fitRoute(state.stops));
          },
          builder: (context, state) => _MapBody(
            state: state,
            controller: _controller,
            mapReady: _mapReady,
            watchdogElapsed: _mapWatchdog?.isActive == false,
            following: _following,
            fallbackCamera: _fallbackCamera,
            busTarget: _busTarget(context, state),
            onMapCreated: (controller) {
              if (!_controller.isCompleted) _controller.complete(controller);
              _mapWatchdog?.cancel();
              setState(() => _mapReady = true);
              unawaited(_fitRoute(state.stops));
            },
            onPanned: () {
              if (_following) setState(() => _following = false);
            },
            onRecentre: _recentre,
          ),
        );
      },
    );
  }

  /// Where to draw the bus.
  ///
  /// The server's position wins: it has been through the plausibility gate and
  /// the road-snapping provider, so it is what the office and the parents are
  /// looking at, and a driver comparing screens should see the same bus. The
  /// device's own fix is the fallback for the window before the first position
  /// has made the round trip.
  LatLng? _busTarget(BuildContext context, TrackingState state) {
    final server = state.trip?.position;
    if (server != null) return LatLng(server.latitude, server.longitude);

    final own = context.read<GpsCubit>().state.lastFix;
    return own == null ? null : LatLng(own.latitude, own.longitude);
  }
}

class _MapBody extends StatelessWidget {
  const _MapBody({
    required this.state,
    required this.controller,
    required this.mapReady,
    required this.watchdogElapsed,
    required this.following,
    required this.fallbackCamera,
    required this.busTarget,
    required this.onMapCreated,
    required this.onPanned,
    required this.onRecentre,
  });

  final TrackingState state;
  final Completer<GoogleMapController> controller;
  final bool mapReady;
  final bool watchdogElapsed;
  final bool following;
  final CameraPosition fallbackCamera;
  final LatLng? busTarget;
  final void Function(GoogleMapController) onMapCreated;
  final VoidCallback onPanned;
  final Future<void> Function(LatLng) onRecentre;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final theme = Theme.of(context);
    final colors = context.ctms;
    final dark = theme.brightness == Brightness.dark;

    return Scaffold(
      body: Stack(
        children: [
          GoogleMap(
            initialCameraPosition: busTarget == null
                ? fallbackCamera
                : CameraPosition(target: busTarget!, zoom: 15),
            onMapCreated: onMapCreated,
            style: dark ? MapStyles.dark : null,
            myLocationEnabled: true,
            myLocationButtonEnabled: false,
            compassEnabled: false,
            zoomControlsEnabled: false,
            mapToolbarEnabled: false,
            markers: _markers(context, strings, colors),
            polylines: _polylines(colors),
            onCameraMoveStarted: onPanned,
          ),

          // The SDK never came back. Distinct from having no bus: the map
          // itself is what failed, and no amount of GPS will fix it.
          if (!mapReady && watchdogElapsed)
            Positioned.fill(child: _MapUnavailable(strings: strings)),

          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(Spacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Status first, per the information hierarchy: whether the
                  // office can see this bus outranks where it is.
                  BlocBuilder<GpsCubit, GpsState>(
                    builder: (context, gps) => GpsStatusPill(state: gps),
                  ),
                  if (state.isStale || state.isStalled) ...[
                    const SizedBox(height: Spacing.sm),
                    _PositionWarning(state: state),
                  ],
                ],
              ),
            ),
          ),

          if (busTarget != null && !following)
            Positioned(
              right: Spacing.md,
              bottom: Sizes.buttonProminent * 2.6,
              child: FloatingActionButton.large(
                onPressed: () => onRecentre(busTarget!),
                tooltip: strings.mapRecentre,
                child: const AppIconView(AppIcon.gpsLive, size: IconSize.lg),
              ),
            ),

          Align(
            alignment: Alignment.bottomCenter,
            child: NextStopSheet(state: state),
          ),
        ],
      ),
    );
  }

  Set<Marker> _markers(
    BuildContext context,
    AppStrings strings,
    CtmsColors colors,
  ) {
    final trip = state.trip;

    return {
      for (final stop in state.stops)
        Marker(
          markerId: MarkerId('stop-${stop.id}'),
          position: LatLng(stop.latitude, stop.longitude),
          icon: BitmapDescriptor.defaultMarkerWithHue(
            _hueFor(_stateOf(trip, stop.id)),
          ),
          infoWindow: InfoWindow(
            title: stop.name,
            snippet: stop.landmark ?? stop.address?.split('\n').first,
          ),
        ),
      if (busTarget != null)
        Marker(
          markerId: const MarkerId('bus'),
          position: busTarget!,
          zIndexInt: 2,
          // Faded when the server says the position is not current. A
          // fresh-looking bus over an old fix is the lie this screen most
          // easily tells.
          alpha: state.isStale ? 0.45 : 1,
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueAzure),
          infoWindow: InfoWindow(title: strings.mapBusMarker),
        ),
    };
  }

  /// The route, drawn through the stops in order.
  ///
  /// The stop coordinates are CTMS's own. The backend's routing provider does
  /// fetch a road-following polyline, but no endpoint exposes it — see the note
  /// in the slice report — so this is the real geometry the API offers rather
  /// than a shape invented here.
  Set<Polyline> _polylines(CtmsColors colors) {
    if (state.stops.length < 2) return const {};

    return {
      Polyline(
        polylineId: const PolylineId('route'),
        points: [
          for (final stop in state.stops) LatLng(stop.latitude, stop.longitude),
        ],
        width: 6,
        color: colors.info,
      ),
    };
  }

  StopState _stateOf(LiveTrip? trip, String stopId) {
    if (trip == null) return StopState.unknown;

    for (final stop in trip.stops) {
      if (stop.stopId == stopId) return stop.state;
    }
    return StopState.unknown;
  }

  double _hueFor(StopState state) => switch (state) {
        StopState.arrived => BitmapDescriptor.hueGreen,
        StopState.departed => BitmapDescriptor.hueViolet,
        StopState.skipped => BitmapDescriptor.hueRose,
        StopState.approaching => BitmapDescriptor.hueOrange,
        _ => BitmapDescriptor.hueYellow,
      };
}

/// The map surface itself failed. Almost always credentials.
class _MapUnavailable extends StatelessWidget {
  const _MapUnavailable({required this.strings});

  final AppStrings strings;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: Theme.of(context).colorScheme.surface,
      child: EmptyState(
        icon: AppIcon.map,
        title: strings.mapUnavailableTitle,
        body: strings.mapUnavailableBody,
      ),
    );
  }
}

/// Says which of the two things is wrong: an old position, or a poll that is
/// no longer landing. They look identical on a map and are not the same.
class _PositionWarning extends StatelessWidget {
  const _PositionWarning({required this.state});

  final TrackingState state;

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    final colors = context.ctms;
    final age = state.trip?.position?.ageSeconds;

    final message = state.isStalled
        ? strings.mapPollFailed
        : (age == null
            ? strings.mapPositionStale
            : strings.mapPositionAge((age / 60).ceil()));

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
            child: Text(message, style: Theme.of(context).textTheme.bodyMedium),
          ),
        ],
      ),
    );
  }
}
