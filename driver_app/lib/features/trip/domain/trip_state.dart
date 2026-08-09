import '../../../core/api/api_failure.dart';
import 'trip.dart';

/// M1 — the trip.
///
/// Eight states, exactly as `docs/driver-app/04-state-machines.md` defines
/// them. Two distinctions in here are the whole point of the machine:
///
/// * [TripNone] and [TripUnavailable] are not the same. `none` is an answer —
///   the server said there is no trip today. `unavailable` is the absence of
///   one, and claiming "no trip assigned" when we simply could not ask would
///   tell a driver they have no work.
/// * A failed refresh is not a state. Once a trip is loaded it stays loaded;
///   the failure rides along on [stale] and [failure] so the trip stays on
///   screen through a tunnel.
sealed class TripState {
  const TripState({this.stale = false, this.failure});

  /// The data on screen is held over from an earlier read that has since
  /// failed to refresh. Never presented as current.
  final bool stale;

  /// Why the last refresh did not land. Present only alongside [stale].
  final ApiFailure? failure;

  /// The trip this state carries, when it carries one.
  Trip? get trip => null;

  ServiceReadiness? get readiness => null;
}

/// Fetching, with nothing authoritative held yet.
final class TripLoading extends TripState {
  const TripLoading();
}

/// The server answered: no trip today. A calm, empty screen — not an error.
final class TripNone extends TripState {
  const TripNone({super.stale, super.failure});
}

/// The first read failed and nothing is cached, so the app cannot honestly say
/// whether a trip exists. Renders the error card with Retry.
final class TripUnavailable extends TripState {
  const TripUnavailable(this.reason);

  /// What went wrong, for the retry affordance to explain.
  final ApiFailure reason;

  @override
  ApiFailure? get failure => reason;
}

/// A trip exists but the bus is not cleared to carry anyone.
final class TripBlocked extends TripState {
  const TripBlocked({
    required this.value,
    required this.clearance,
    super.stale,
    super.failure,
  });

  final Trip value;
  final ServiceReadiness clearance;

  @override
  Trip? get trip => value;

  @override
  ServiceReadiness? get readiness => clearance;
}

/// Cleared to start.
///
/// [starting] is a parameter rather than a ninth state, the same idiom M1 uses
/// for `ready(stale)`: the request is in flight, the screen is unchanged, and
/// only the button knows.
final class TripReady extends TripState {
  const TripReady({
    required this.value,
    required this.clearance,
    this.starting = false,
    this.refusal,
    super.stale,
    super.failure,
  });

  final Trip value;
  final ServiceReadiness clearance;

  /// A start request is in flight.
  final bool starting;

  /// The server's last refusal, in its own words, when it was one that does not
  /// change what the trip is — a rate limit, a server fault, or no connection.
  /// Refusals that do change it move state instead.
  final String? refusal;

  @override
  Trip? get trip => value;

  @override
  ServiceReadiness? get readiness => clearance;
}

/// Cleared, but outside the start window. Time-based and self-resolving.
///
/// Reached only from a start refusal: the window length is server configuration
/// (`checkin_window_minutes`) and appears in no payload, so the client cannot
/// know a trip is early until it asks.
final class TripWaiting extends TripState {
  const TripWaiting({
    required this.value,
    required this.clearance,
    required this.message,
    super.stale,
    super.failure,
  });

  final Trip value;
  final ServiceReadiness clearance;

  /// The server's refusal verbatim, which names the time the trip may start.
  ///
  /// The only place that time exists: the window opens some configured number
  /// of minutes before departure and that number appears in no payload, so
  /// there is nothing here to count down to and nothing to recompute.
  final String message;

  @override
  Trip? get trip => value;

  @override
  ServiceReadiness? get readiness => clearance;
}

/// In progress.
final class TripRunning extends TripState {
  const TripRunning({required this.value, super.stale, super.failure});

  final Trip value;

  @override
  Trip? get trip => value;
}

/// Finished or cancelled.
final class TripClosed extends TripState {
  const TripClosed({required this.value, super.stale, super.failure});

  final Trip value;

  @override
  Trip? get trip => value;
}
