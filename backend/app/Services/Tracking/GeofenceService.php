<?php

namespace App\Services\Tracking;

use App\Enums\StopProgressState;
use App\Events\Tracking\BusApproachingStop;
use App\Events\Tracking\BusArrivedAtStop;
use App\Events\Tracking\StopSkipped;
use App\Exceptions\BusinessRuleException;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The geofence state machine (BR-308).
 *
 * Arrival is a transition confirmed over several readings, not a single point.
 * GPS drifts: a bus on a parallel road, or one bad fix, would otherwise fire
 * "your bus is here" and send a student into the road for a bus that is not
 * there. Confirmation costs a few seconds and removes an entire class of false
 * alarm.
 */
class GeofenceService
{
    /**
     * Build the stop-progress rows for a trip. Idempotent.
     */
    public function initialiseFor(Trip $trip): void
    {
        $stops = $trip->route?->stops()->get() ?? collect();

        foreach ($stops as $stop) {
            // Nothing on this model is fillable — the state machine owns it —
            // so the row is located and stamped explicitly.
            $progress = TripStopProgress::where('trip_id', $trip->getKey())
                ->where('route_stop_id', $stop->getKey())
                ->first() ?? new TripStopProgress;

            $progress->forceFill([
                'trip_id' => $trip->getKey(),
                'route_stop_id' => $stop->getKey(),
                'sequence_number' => $stop->sequence_number,
            ])->save();
        }
    }

    /**
     * Advance the state machine for every stop, given a new position.
     *
     * @return array<int, TripStopProgress> Rows whose state changed.
     */
    public function evaluate(Trip $trip, float $latitude, float $longitude): array
    {
        $changed = [];

        $progressRows = TripStopProgress::with('stop')
            ->where('trip_id', $trip->getKey())
            ->pending()
            ->orderBy('sequence_number')
            ->get();

        foreach ($progressRows as $progress) {
            $stop = $progress->stop;

            if ($stop === null) {
                continue;
            }

            $inside = $stop->isWithinGeofence($latitude, $longitude);

            $transitioned = $inside
                ? $this->handleInside($trip, $progress)
                : $this->handleOutside($progress);

            if ($transitioned) {
                $changed[] = $progress;
            }
        }

        // Leaving a stop is only detectable once the bus is outside its fence.
        foreach ($this->arrivedRows($trip) as $arrived) {
            if ($arrived->stop !== null && ! $arrived->stop->isWithinGeofence($latitude, $longitude)) {
                $this->depart($trip, $arrived);
                $changed[] = $arrived;
            }
        }

        return $changed;
    }

    /**
     * A stop the bus passed without serving (BR-208 in F-07).
     *
     * @throws BusinessRuleException
     */
    public function skip(Trip $trip, TripStopProgress $progress, string $reason, User $actor): TripStopProgress
    {
        return DB::transaction(function () use ($trip, $progress, $reason) {
            if (! $progress->state->canTransitionTo(StopProgressState::SKIPPED)) {
                throw new BusinessRuleException(
                    "This stop is {$progress->state->value} and cannot be skipped.",
                );
            }

            $progress->forceFill([
                'state' => StopProgressState::SKIPPED,
                'skip_reason' => $reason,
            ])->save();

            // The people waiting there need to know immediately, not when the
            // trip ends.
            StopSkipped::dispatch($trip, $progress, $reason);

            return $progress;
        });
    }

    /**
     * Mark arrival manually — the fallback when GPS is unavailable (BR-306).
     */
    public function markArrived(Trip $trip, TripStopProgress $progress): TripStopProgress
    {
        if ($progress->state === StopProgressState::ARRIVED) {
            return $progress; // Idempotent.
        }

        if (! $progress->state->canTransitionTo(StopProgressState::ARRIVED)) {
            throw new BusinessRuleException(
                "This stop is {$progress->state->value} and cannot be marked as arrived.",
            );
        }

        $this->arrive($trip, $progress);

        return $progress;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    private function handleInside(Trip $trip, TripStopProgress $progress): bool
    {
        $required = (int) config('ctms.gps.geofence_confirm_readings', 2);
        $readings = $progress->inside_readings + 1;

        if ($progress->state === StopProgressState::PENDING) {
            $progress->forceFill([
                'state' => StopProgressState::APPROACHING,
                'entered_at' => now(),
                'inside_readings' => $readings,
            ])->save();

            // N-02 — fired on entry, once per stop per trip, so the people
            // waiting have time to reach the kerb.
            BusApproachingStop::dispatch($trip, $progress);

            if ($readings >= $required) {
                $this->arrive($trip, $progress);
            }

            return true;
        }

        $progress->forceFill(['inside_readings' => $readings])->save();

        if ($readings >= $required) {
            $this->arrive($trip, $progress);

            return true;
        }

        return false;
    }

    /**
     * Drifting back out before confirmation is not an arrival — reset.
     */
    private function handleOutside(TripStopProgress $progress): bool
    {
        if ($progress->state !== StopProgressState::APPROACHING) {
            return false;
        }

        $progress->forceFill([
            'state' => StopProgressState::PENDING,
            'entered_at' => null,
            'inside_readings' => 0,
        ])->save();

        return true;
    }

    private function arrive(Trip $trip, TripStopProgress $progress): void
    {
        $progress->forceFill([
            'state' => StopProgressState::ARRIVED,
            'arrived_at' => now(),
        ])->save();

        BusArrivedAtStop::dispatch($trip, $progress);
    }

    private function depart(Trip $trip, TripStopProgress $progress): void
    {
        $progress->forceFill([
            'state' => StopProgressState::DEPARTED,
            'departed_at' => now(),
        ])->save();
    }

    /**
     * @return Collection<int, TripStopProgress>
     */
    private function arrivedRows(Trip $trip)
    {
        return TripStopProgress::with('stop')
            ->where('trip_id', $trip->getKey())
            ->where('state', StopProgressState::ARRIVED->value)
            ->get();
    }
}
