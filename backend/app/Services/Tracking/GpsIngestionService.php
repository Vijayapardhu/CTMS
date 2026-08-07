<?php

namespace App\Services\Tracking;

use App\Contracts\Maps\RoadsProvider;
use App\Enums\TripStatus;
use App\Events\Tracking\TripDelayed;
use App\Events\Tracking\TripPositionUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\User;
use App\Services\Maps\Support\LatLng;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The GPS ingestion pipeline (BR-300 to BR-308).
 *
 * Every position runs the same sequence, in this order, and nothing bypasses
 * it:
 *
 *   1. Trip is running          6. Distance and speed derivation
 *   2. Caller is the driver     7. Geofence evaluation
 *   3. Timestamp validation     8. ETA recalculation
 *   4. Duplicate detection      9. Delay detection
 *   5. Plausibility            10. Persist, then broadcast
 *
 * Ordering is not incidental. Plausibility runs before persistence because a
 * rejected point must never become truth (BR-301); geofencing runs before ETA
 * because arrival changes what remains to estimate; persistence runs before
 * broadcast because a subscriber must never see a position the database does
 * not have.
 */
class GpsIngestionService
{
    public function __construct(
        private readonly GeofenceService $geofences,
        private readonly EtaService $etas,
        private readonly RoadsProvider $roads,
    ) {}

    /**
     * Ingest one position.
     *
     * @param  array<string, mixed>  $reading
     *
     * @throws BusinessRuleException
     */
    public function ingest(Trip $trip, array $reading, User $actor): ?TripLocation
    {
        // ---- 1. The trip must be running -------------------------------
        if ($trip->status !== TripStatus::RUNNING) {
            throw new BusinessRuleException(
                "Positions are only accepted for a running trip. This trip is {$trip->status->value}.",
            );
        }

        // ---- 2. Only the assigned driver may report ---------------------
        // BR-300 — otherwise anyone with a token can spoof a bus's location.
        $this->assertIsAssignedDriver($trip, $actor);

        $latitude = (float) $reading['latitude'];
        $longitude = (float) $reading['longitude'];

        // ---- 3. Timestamps ---------------------------------------------
        [$recordedAt, $deviceRecordedAt, $skewed] = $this->resolveTimestamps($reading);

        // ---- 4. Duplicates ---------------------------------------------
        $idempotencyKey = $reading['idempotency_key'] ?? null;

        if ($idempotencyKey !== null && $this->alreadyIngested($trip, $idempotencyKey)) {
            return null; // Absorbed silently; an offline replay is not an error.
        }

        $previous = $this->lastPositionFor($trip);

        // ---- 5. Plausibility -------------------------------------------
        $rejection = $this->implausibilityReason($trip, $latitude, $longitude, $reading, $previous, $recordedAt);

        if ($rejection !== null) {
            // BR-301 — logged, never stored as truth. One bad point corrupts
            // every ETA downstream.
            Log::warning('Implausible GPS reading rejected', [
                'trip_id' => (string) $trip->getKey(),
                'reason' => $rejection,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            throw new BusinessRuleException("Position rejected: {$rejection}", ['reason' => $rejection]);
        }

        // ---- 5b. Snap to road ------------------------------------------
        // Deliberately after the plausibility gate: snapping a wild reading
        // would drag it onto a real road and make nonsense look credible. The
        // gate decides whether to believe the point; snapping only tidies one
        // already believed. Falls through unchanged when Roads is unavailable.
        $snapped = $this->roads->snap(LatLng::make($latitude, $longitude));
        $latitude = $snapped->latitude;
        $longitude = $snapped->longitude;

        // ---- 6. Derivation ---------------------------------------------
        $speed = $reading['speed_kmh'] ?? $this->deriveSpeed($previous, $latitude, $longitude, $recordedAt);

        // ---- 10a. Persist ----------------------------------------------
        // Written before geofencing so that any event a geofence transition
        // publishes refers to a position that already exists.
        $location = $this->persist($trip, $latitude, $longitude, $speed, $reading,
            $recordedAt, $deviceRecordedAt, $skewed, $idempotencyKey);

        if ($location === null) {
            return null; // Lost an idempotency race; the other write stands.
        }

        $trip->forceFill([
            'current_latitude' => $latitude,
            'current_longitude' => $longitude,
            'last_gps_update' => $recordedAt,
            'average_speed_kmh' => $speed ?? $trip->average_speed_kmh,
        ])->save();

        // The driver is where their bus is. `Driver::updateCurrentLocation()`
        // existed from the start and nothing ever called it, which meant every
        // driver's position stayed null — and the replacement search ranks
        // candidate vehicles by *driver* proximity (BR-359). With no positions
        // it silently fell back to picking the biggest bus, so a breakdown
        // could be answered by a vehicle on the far side of the city.
        $trip->driver?->updateCurrentLocation($latitude, $longitude);

        // ---- 7. Geofence -----------------------------------------------
        $this->geofences->evaluate($trip, $latitude, $longitude);

        // ---- 8. ETA ----------------------------------------------------
        $this->etas->recalculate($trip, $latitude, $longitude);

        // ---- 9. Delay --------------------------------------------------
        $this->detectDelay($trip);

        // ---- 10b. Broadcast --------------------------------------------
        TripPositionUpdated::dispatch($trip, $location);

        return $location;
    }

    // ========================================================================
    // PIPELINE STEPS
    // ========================================================================

    /**
     * @throws BusinessRuleException
     */
    private function assertIsAssignedDriver(Trip $trip, User $actor): void
    {
        $isAssigned = $actor->isDriver()
            && $actor->driver !== null
            && (string) $actor->driver->getKey() === (string) $trip->driver_id;

        if (! $isAssigned) {
            throw new BusinessRuleException(
                'Position updates are only accepted from the assigned driver.',
            );
        }
    }

    /**
     * The server clock is authoritative for ordering; the device clock is
     * recorded but flagged when it disagrees beyond tolerance.
     *
     * @param  array<string, mixed>  $reading
     * @return array{0: CarbonInterface, 1: CarbonInterface|null, 2: bool}
     */
    private function resolveTimestamps(array $reading): array
    {
        $deviceRecordedAt = isset($reading['recorded_at'])
            ? Carbon::parse($reading['recorded_at'])
            : null;

        if ($deviceRecordedAt === null) {
            return [now(), null, false];
        }

        $tolerance = (int) config('ctms.gps.clock_skew_tolerance_seconds', 120);
        $skewed = abs(now()->diffInSeconds($deviceRecordedAt, false)) > $tolerance;

        // A device that believes it is tomorrow must not push a position into
        // the future and freeze every subsequent reading out as "older".
        return [$skewed ? now() : $deviceRecordedAt, $deviceRecordedAt, $skewed];
    }

    private function alreadyIngested(Trip $trip, string $idempotencyKey): bool
    {
        return TripLocation::where('trip_id', $trip->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    private function lastPositionFor(Trip $trip): ?TripLocation
    {
        return TripLocation::where('trip_id', $trip->getKey())
            ->latest('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * BR-302 — impossible speed, impossible jump, outside the service region,
     * or accuracy worse than the threshold.
     *
     * @param  array<string, mixed>  $reading
     */
    private function implausibilityReason(
        Trip $trip,
        float $latitude,
        float $longitude,
        array $reading,
        ?TripLocation $previous,
        CarbonInterface $recordedAt,
    ): ?string {
        $area = config('ctms.service_area');

        $insideArea = $latitude >= $area['min_latitude'] && $latitude <= $area['max_latitude']
            && $longitude >= $area['min_longitude'] && $longitude <= $area['max_longitude'];

        if (! $insideArea) {
            return 'outside the service area';
        }

        $accuracyThreshold = (float) config('ctms.gps.accuracy_threshold_meters', 50);

        if (isset($reading['accuracy_meters']) && (float) $reading['accuracy_meters'] > $accuracyThreshold) {
            return 'accuracy below the usable threshold';
        }

        if ($previous === null) {
            return null; // Nothing to compare against.
        }

        $metres = $this->distanceInMetres(
            (float) $previous->latitude, (float) $previous->longitude,
            $latitude, $longitude,
        );

        if ($metres > (int) config('ctms.gps.max_jump_metres', 5000)) {
            return 'implausible jump from the previous position';
        }

        $seconds = abs($previous->recorded_at->diffInSeconds($recordedAt, false));

        if ($seconds > 0) {
            $kmh = ($metres / 1000) / ($seconds / 3600);

            if ($kmh > (int) config('ctms.gps.max_speed_kmh', 150)) {
                return 'implausible speed';
            }
        }

        return null;
    }

    private function deriveSpeed(?TripLocation $previous, float $lat, float $lng, CarbonInterface $at): ?float
    {
        if ($previous === null) {
            return null;
        }

        $seconds = abs($previous->recorded_at->diffInSeconds($at, false));

        if ($seconds <= 0) {
            return null;
        }

        $metres = $this->distanceInMetres(
            (float) $previous->latitude, (float) $previous->longitude, $lat, $lng,
        );

        return round(($metres / 1000) / ($seconds / 3600), 2);
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    private function persist(
        Trip $trip,
        float $latitude,
        float $longitude,
        ?float $speed,
        array $reading,
        CarbonInterface $recordedAt,
        ?CarbonInterface $deviceRecordedAt,
        bool $skewed,
        ?string $idempotencyKey,
    ): ?TripLocation {
        try {
            return DB::transaction(function () use (
                $trip, $latitude, $longitude, $speed, $reading,
                $recordedAt, $deviceRecordedAt, $skewed, $idempotencyKey
            ) {
                $location = new TripLocation;

                $location->forceFill([
                    'trip_id' => $trip->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'accuracy_meters' => $reading['accuracy_meters'] ?? null,
                    'speed_kmh' => $speed,
                    'heading' => $reading['heading'] ?? null,
                    'altitude_meters' => $reading['altitude_meters'] ?? null,
                    'recorded_at' => $recordedAt,
                    'device_recorded_at' => $deviceRecordedAt,
                    'clock_skew_suspected' => $skewed,
                ])->save();

                return $location;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Delay is an event, not a field read off a screen — so reports,
     * dashboards and notifications all react to the same signal.
     */
    private function detectDelay(Trip $trip): void
    {
        $threshold = (int) config('ctms.delay.notify_threshold_minutes', 10);
        $delay = $this->etas->projectedDelayMinutes($trip);

        if ($delay < $threshold) {
            return;
        }

        // The event's own dedup key bounds this to one message per threshold
        // band per trip, so a bus that stays late does not notify every ping.
        TripDelayed::dispatch($trip, $delay);
    }

    /**
     * Great-circle distance in metres.
     */
    private function distanceInMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000;

        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad($lng2) - deg2rad($lng1);

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }
}
