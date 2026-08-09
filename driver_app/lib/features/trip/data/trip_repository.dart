import '../../../core/api/api_failure.dart';
import '../domain/trip.dart';
import 'trip_api.dart';

/// One day's trip and, when it matters, whether the bus is cleared.
class TripSnapshot {
  const TripSnapshot({this.trip, this.readiness});

  final Trip? trip;

  /// Only fetched when the trip is SCHEDULED. A running or closed trip is past
  /// the gate, and asking again would spend a request to learn nothing.
  final ServiceReadiness? readiness;

  bool get isEmpty => trip == null;
}

/// Turns the two endpoints into the one thing the bloc needs.
///
/// The readiness call is deliberately *not* fatal. A trip the driver can see
/// but whose clearance is unknown is far more useful than an error screen, so
/// a readiness failure degrades to "not cleared, and here is why we cannot
/// say" rather than sinking the whole read.
class TripRepository {
  const TripRepository(this._api);

  final TripApi _api;

  /// Starts the trip and returns what the server says it now is.
  ///
  /// No local optimism: the trip the caller renders afterwards is the server's
  /// row, not a client-side guess at what starting must have done. Failures
  /// propagate — the refusal *is* the answer here, and swallowing it the way
  /// [load] swallows a readiness failure would clear a bus nobody cleared.
  Future<Trip> start(String tripId) => _api.start(tripId);

  Future<TripSnapshot> load({DateTime? on}) async {
    final trips = await _api.today(on: on);

    if (trips.isEmpty) return const TripSnapshot();

    // A driver has one trip in play at a time. Anything RUNNING wins — the app
    // must resume it — otherwise the earliest departure is the one they are
    // about to do.
    final trip = trips.firstWhere(
      (t) => t.status == TripStatus.running,
      orElse: () => (trips.toList()
            ..sort((a, b) => (a.scheduledDeparture ?? '')
                .compareTo(b.scheduledDeparture ?? '')))
          .first,
    );

    if (trip.status != TripStatus.scheduled || trip.busId == null) {
      return TripSnapshot(trip: trip);
    }

    try {
      return TripSnapshot(
        trip: trip,
        readiness: await _api.readiness(trip.busId!),
      );
    } on ApiFailure catch (e) {
      return TripSnapshot(
        trip: trip,
        readiness: ServiceReadiness(
          cleared: false,
          reasons: [e.message],
          checkedAt: DateTime.now(),
        ),
      );
    }
  }
}
