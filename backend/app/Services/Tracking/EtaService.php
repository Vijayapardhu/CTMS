<?php

namespace App\Services\Tracking;

use App\Contracts\Maps\RoutingProvider;
use App\Enums\StopProgressState;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Services\Maps\Support\LatLng;

/**
 * Arrival estimates (FR-09).
 *
 * Estimates come from the bound {@see RoutingProvider}. With Google configured
 * that is the Route Matrix, traffic-aware and road-following; with no provider
 * configured it is distance over observed speed. BR-902 requires the system to
 * keep producing usable ETAs when the provider is unavailable, so both paths
 * have to be correct — and the offline one is what the test suite exercises,
 * which means the fallback is the better-tested of the two.
 *
 * Every estimate is labelled with how it was produced, because a schedule-based
 * guess presented as a live ETA sends someone into the road at the wrong moment.
 */
class EtaService
{
    /** Assumed average speed when nothing better is known, in km/h. */
    private const FALLBACK_SPEED_KMH = 25.0;

    public function __construct(private readonly RoutingProvider $routing) {}

    /**
     * Recalculate the ETA for every stop still ahead.
     *
     * @return array<int, TripStopProgress>
     */
    public function recalculate(Trip $trip, float $latitude, float $longitude): array
    {
        $updated = [];

        $pending = TripStopProgress::with('stop')
            ->where('trip_id', $trip->getKey())
            ->pending()
            ->orderBy('sequence_number')
            ->get()
            ->filter(fn (TripStopProgress $progress) => $progress->stop !== null)
            ->values();

        if ($pending->isEmpty()) {
            return [];
        }

        // One matrix call for every remaining stop rather than one route call
        // each: same answer, a fraction of the cost, and it arrives inside the
        // window between two GPS readings.
        $origin = LatLng::make($latitude, $longitude);

        $destinations = $pending
            ->map(fn (TripStopProgress $p) => LatLng::make(
                (float) $p->stop->latitude,
                (float) $p->stop->longitude,
            ))
            ->all();

        $legs = $this->routing->matrix($origin, $destinations);

        // The matrix is measured from the bus to each stop independently, so
        // the leg to stop N already contains the distance through stops 1..N-1.
        // Only the dwell time at the intervening stops has to be added.
        $dwellSeconds = 0;

        foreach ($pending as $index => $progress) {
            $leg = $legs[$index] ?? null;

            $travelSeconds = $leg !== null
                ? $leg->effectiveSeconds()
                : $this->travelSecondsTo(
                    $latitude, $longitude,
                    $progress->stop->latitude, $progress->stop->longitude,
                    $this->usableSpeed($trip),
                );

            $progress->forceFill([
                'eta_at' => now()->addSeconds($travelSeconds + $dwellSeconds),
            ])->save();

            $dwellSeconds += ($progress->stop->waiting_time_minutes ?? 0) * 60;

            $updated[] = $progress;
        }

        return $updated;
    }

    /**
     * The ETA for one student's stop, with its provenance.
     *
     * @return array{eta_at: string|null, minutes: int|null, basis: string, stops_away: int|null}
     */
    public function forStop(Trip $trip, string $routeStopId): array
    {
        $progress = TripStopProgress::where('trip_id', $trip->getKey())
            ->where('route_stop_id', $routeStopId)
            ->first();

        if ($progress === null) {
            return ['eta_at' => null, 'minutes' => null, 'basis' => 'unknown', 'stops_away' => null];
        }

        if ($progress->state->isTerminal() || $progress->state === StopProgressState::ARRIVED) {
            return [
                'eta_at' => $progress->arrived_at?->toIso8601String(),
                'minutes' => 0,
                'basis' => 'arrived',
                'stops_away' => 0,
            ];
        }

        $stopsAway = TripStopProgress::where('trip_id', $trip->getKey())
            ->pending()
            ->where('sequence_number', '<', $progress->sequence_number)
            ->count();

        // No live position yet means the only honest answer is the timetable.
        if ($progress->eta_at === null || $trip->last_gps_update === null) {
            return [
                'eta_at' => null,
                'minutes' => null,
                'basis' => 'scheduled',
                'stops_away' => $stopsAway,
            ];
        }

        $stale = (int) config('ctms.gps.stale_after_seconds', 120);
        $isStale = $trip->last_gps_update->diffInSeconds(now()) > $stale;

        return [
            'eta_at' => $progress->eta_at->toIso8601String(),
            'minutes' => max(0, (int) now()->diffInMinutes($progress->eta_at, false)),
            // A stale estimate is labelled as such rather than presented live.
            'basis' => $isStale ? 'stale' : 'live',
            'stops_away' => $stopsAway,
        ];
    }

    /**
     * How late the trip is projected to be at its final stop, in minutes.
     */
    public function projectedDelayMinutes(Trip $trip): int
    {
        $last = TripStopProgress::where('trip_id', $trip->getKey())
            ->orderByDesc('sequence_number')
            ->first();

        if ($last?->eta_at === null) {
            // Without an estimate, fall back to how late it left.
            return max(0, $trip->delayMinutes());
        }

        return max(0, (int) $trip->scheduledArrivalAt()->diffInMinutes($last->eta_at, false));
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * Observed speed, floored so a bus stopped at a light does not produce an
     * ETA of several days.
     */
    private function usableSpeed(Trip $trip): float
    {
        $observed = (float) ($trip->average_speed_kmh ?? 0);

        return $observed >= 5.0 ? $observed : self::FALLBACK_SPEED_KMH;
    }

    /**
     * The provider boundary. Straight-line distance over observed speed, with
     * a road-winding factor so estimates are not systematically optimistic.
     */
    private function travelSecondsTo(float $lat1, float $lng1, float $lat2, float $lng2, float $speedKmh): int
    {
        $earthRadius = 6_371_000;

        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad($lng2) - deg2rad($lng1);

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;
        $metres = $earthRadius * 2 * asin(min(1.0, sqrt($a)));

        // Roads are not straight lines; 1.3 is a conventional urban factor.
        $roadMetres = $metres * 1.3;

        return (int) round(($roadMetres / 1000) / max($speedKmh, 1) * 3600);
    }
}
