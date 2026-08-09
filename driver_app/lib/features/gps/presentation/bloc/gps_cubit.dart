import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/services/logger_service.dart';
import '../../../../core/sync/drift_sync_queue.dart';
import '../../../../core/sync/sync_cubit.dart';
import '../../../../core/sync/sync_engine.dart';
import '../../../../core/sync/sync_queue.dart';
import '../../data/location_source.dart';
import '../../domain/gps_state.dart';

/// M3 — the GPS stream.
///
/// The shape that matters: **every fix is enqueued, never posted directly.**
/// There is no live path and no offline path to keep in step with each other,
/// only one path whose latency happens to be short when the network is up. A
/// tunnel is then not a special case — it is the same code with a slower queue,
/// which is why a ninety-minute run with a twenty-minute outage loses nothing.
class GpsCubit extends Cubit<GpsState> {
  GpsCubit({
    required LocationSource source,
    required DriftSyncQueue queue,
    required SyncCubit sync,
    required LoggerService logger,
    Duration noFixAfter = const Duration(seconds: 30),
  })  : _source = source,
        _queue = queue,
        _sync = sync,
        _logger = logger,
        _noFixAfter = noFixAfter,
        super(const GpsIdle());

  final LocationSource _source;
  final DriftSyncQueue _queue;
  final SyncCubit _sync;
  final LoggerService _logger;

  /// M3: no fix for this long and the pill goes grey. The driver is told the
  /// device has nothing, rather than watching "finding position" indefinitely.
  final Duration _noFixAfter;

  StreamSubscription<PositionFix>? _fixes;
  Timer? _noFix;
  String? _tripId;

  /// Starts the stream for a running trip. Idempotent: calling it again for the
  /// trip already being tracked does nothing.
  Future<void> start(String tripId) async {
    if (_tripId == tripId && _fixes != null) return;

    await stop();
    _tripId = tripId;

    final access = await _source.ensureAccess();

    if (access != LocationAccess.granted) {
      emit(GpsDenied(
        permanently: access == LocationAccess.deniedForever ||
            access == LocationAccess.serviceOff,
      ));
      return;
    }

    emit(const GpsAcquiring());
    _armNoFixTimer();

    _fixes = _source.watch().listen(
          _onFix,
          onError: (Object e) {
            _logger.warn('Position stream failed', context: {'error': '$e'});
            emit(GpsNoSignal(count: state.buffered));
          },
        );
  }

  /// Stops tracking. Called when the trip is no longer running, and on sign-out.
  Future<void> stop() async {
    _noFix?.cancel();
    _noFix = null;
    await _fixes?.cancel();
    _fixes = null;
    _tripId = null;

    if (state is! GpsIdle) emit(const GpsIdle());
  }

  /// The trip closed underneath the stream. Everything buffered for it is
  /// discarded: positions for a finished trip are refused, and replaying them
  /// would spend the driver's battery arguing with the server.
  Future<void> tripClosed(String tripId) async {
    await _queue.purgeTrip(tripId);
    await stop();
    await _sync.refresh();
  }

  Future<void> _onFix(PositionFix fix) async {
    _armNoFixTimer();

    final tripId = _tripId;
    if (tripId == null) return;

    // The key is minted here, once, and travels with the row. Every retry of
    // this fix carries the same one, so a replay the server has already seen is
    // absorbed rather than plotted twice.
    final key = _queue.newIdempotencyKey();

    await _queue.enqueue(QueuedAction(
      id: key,
      kind: SyncKinds.position,
      payload: {'trip_id': tripId, ...fix.toJson()},
      idempotencyKey: key,
      sequence: 0,
      createdAt: fix.recordedAt,
      tripId: tripId,
    ));

    final report = await _sync.sync();

    if (report.tripClosed != null) {
      await tripClosed(report.tripClosed!);
      return;
    }

    // What the driver is told is simply whether the queue is draining.
    emit(report.remaining == 0
        ? GpsLive(lastFixAt: fix.recordedAt)
        : GpsBuffering(report.remaining));
  }

  void _armNoFixTimer() {
    _noFix?.cancel();
    _noFix = Timer(_noFixAfter, () {
      if (state is GpsIdle || state is GpsDenied) return;
      emit(GpsNoSignal(count: state.buffered));
    });
  }

  @override
  Future<void> close() async {
    await stop();
    return super.close();
  }
}
