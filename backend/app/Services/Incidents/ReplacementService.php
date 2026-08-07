<?php

namespace App\Services\Incidents;

use App\Enums\ReplacementStatus;
use App\Events\Incidents\ReplacementDispatched;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\ReplacementAssignment;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Replacement vehicles (FR-12, BR-359, BR-360).
 *
 * The system proposes; a human decides. Dispatching a replacement costs money
 * and pulls a vehicle off another duty, so recommendation and approval are
 * separate states with separate actors.
 */
class ReplacementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Rank the available vehicles and record the best as a recommendation.
     */
    public function recommendFor(VehicleIncident $incident, User $actor): ?ReplacementAssignment
    {
        $trip = $incident->trip;
        $originalBus = $incident->bus;

        if ($trip === null || $originalBus === null) {
            return null;
        }

        // Don't stack recommendations on a trip that already has one open.
        $existing = ReplacementAssignment::where('trip_id', $trip->getKey())->open()->first();

        if ($existing !== null) {
            return $existing;
        }

        $candidate = $this->bestCandidate($trip, $originalBus);

        return DB::transaction(function () use ($incident, $trip, $originalBus, $candidate, $actor) {
            $assignment = new ReplacementAssignment;

            $assignment->forceFill([
                'trip_id' => $trip->getKey(),
                'vehicle_incident_id' => $incident->getKey(),
                'original_bus_id' => $originalBus->getKey(),
                // Null when nothing suitable exists — the request still stands,
                // so operations can see that a trip needs a vehicle and none
                // is available, rather than seeing nothing at all.
                'replacement_bus_id' => $candidate?->getKey(),
                'status' => ReplacementStatus::RECOMMENDED,
                'reason' => "{$incident->incident_type->label()}: {$incident->description}",
                'passengers_to_transfer' => $trip->occupied_seat_count,
                'distance_metres' => $candidate === null ? null : $this->distanceToTrip($candidate, $trip),
                'requested_by_id' => $actor->getKey(),
            ])->save();

            $this->audit->log(
                action: 'REPLACEMENT_RECOMMENDED',
                table: $assignment->getTable(),
                recordId: (string) $assignment->getKey(),
                new: [
                    'trip_id' => (string) $trip->getKey(),
                    'candidate_bus_id' => (string) $candidate?->getKey(),
                    'passengers_to_transfer' => $trip->occupied_seat_count,
                ],
                actor: $actor,
            );

            return $assignment;
        });
    }

    /**
     * Approve a recommendation, optionally overriding the chosen vehicle.
     *
     * @throws BusinessRuleException
     */
    public function approve(
        ReplacementAssignment $assignment,
        User $actor,
        ?Bus $bus = null,
        ?Driver $driver = null,
    ): ReplacementAssignment {
        return DB::transaction(function () use ($assignment, $actor, $bus, $driver) {
            $assignment = ReplacementAssignment::whereKey($assignment->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($assignment, ReplacementStatus::APPROVED);

            $chosen = $bus ?? $assignment->replacementBus;

            if ($chosen === null) {
                throw new BusinessRuleException(
                    'No replacement vehicle has been chosen for this request.',
                );
            }

            $chosen = Bus::whereKey($chosen->getKey())->lockForUpdate()->firstOrFail();

            $this->assertBusIsUsable($chosen);

            // BR-360 — a replacement that cannot carry everyone is not a
            // replacement; it is a second problem.
            if ($chosen->seating_capacity < $assignment->passengers_to_transfer) {
                throw new BusinessRuleException(
                    "This bus has {$chosen->seating_capacity} seats but {$assignment->passengers_to_transfer} passengers need transferring.",
                    [
                        'capacity' => $chosen->seating_capacity,
                        'passengers_to_transfer' => $assignment->passengers_to_transfer,
                    ],
                );
            }

            $assignment->forceFill([
                'replacement_bus_id' => $chosen->getKey(),
                'replacement_driver_id' => $driver?->getKey() ?? $assignment->replacement_driver_id,
                'status' => ReplacementStatus::APPROVED,
                'approved_by_id' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'REPLACEMENT_APPROVED',
                table: $assignment->getTable(),
                recordId: (string) $assignment->getKey(),
                new: [
                    'replacement_bus_id' => (string) $chosen->getKey(),
                    'approved_by' => (string) $actor->getKey(),
                ],
                actor: $actor,
            );

            return $assignment;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function reject(ReplacementAssignment $assignment, string $reason, User $actor): ReplacementAssignment
    {
        return DB::transaction(function () use ($assignment, $reason, $actor) {
            $assignment = ReplacementAssignment::whereKey($assignment->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($assignment, ReplacementStatus::REJECTED);

            $assignment->forceFill([
                'status' => ReplacementStatus::REJECTED,
                'rejection_reason' => $reason,
                'approved_by_id' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'REPLACEMENT_REJECTED',
                table: $assignment->getTable(),
                recordId: (string) $assignment->getKey(),
                new: ['reason' => $reason],
                actor: $actor,
            );

            return $assignment;
        });
    }

    /**
     * Send the approved vehicle, and tell the people waiting for it.
     *
     * @throws BusinessRuleException
     */
    public function dispatch(ReplacementAssignment $assignment, User $actor): ReplacementAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $assignment = ReplacementAssignment::whereKey($assignment->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($assignment, ReplacementStatus::DISPATCHED);

            $assignment->forceFill([
                'status' => ReplacementStatus::DISPATCHED,
                'dispatched_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'REPLACEMENT_DISPATCHED',
                table: $assignment->getTable(),
                recordId: (string) $assignment->getKey(),
                actor: $actor,
            );

            ReplacementDispatched::dispatch(
                $assignment->fresh(['trip.route', 'replacementBus', 'originalBus']),
            );

            return $assignment;
        });
    }

    /**
     * The replacement is on scene; the trip continues on the new vehicle.
     *
     * @throws BusinessRuleException
     */
    public function markArrived(ReplacementAssignment $assignment, User $actor): ReplacementAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $assignment = ReplacementAssignment::whereKey($assignment->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($assignment, ReplacementStatus::ARRIVED);

            $assignment->forceFill([
                'status' => ReplacementStatus::ARRIVED,
                'arrived_at' => now(),
            ])->save();

            // The trip moves onto the replacement vehicle, preserving its
            // identity and therefore its attendance and its history.
            $trip = Trip::whereKey($assignment->trip_id)->lockForUpdate()->first();

            if ($trip !== null && $assignment->replacement_bus_id !== null && ! $trip->isTerminal()) {
                $before = $trip->getAttributes();

                $trip->forceFill([
                    'bus_id' => $assignment->replacement_bus_id,
                    'driver_id' => $assignment->replacement_driver_id ?? $trip->driver_id,
                ])->save();

                $this->audit->updated($trip, $before, $actor);
            }

            return $assignment;
        });
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * The nearest available bus with enough seats.
     *
     * Proximity is measured from the incident, because a bus twenty minutes
     * away is not a replacement for a broken-down vehicle full of children.
     */
    private function bestCandidate(Trip $trip, Bus $exclude): ?Bus
    {
        $candidates = Bus::query()
            ->available()
            ->whereKeyNot($exclude->getKey())
            ->where('seating_capacity', '>=', max($trip->occupied_seat_count, 1))
            ->get()
            // A bus without valid documents cannot legally carry anyone,
            // however close it is (BR-055).
            ->filter(fn (Bus $bus) => $bus->hasValidDocuments());

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($trip->current_latitude === null) {
            return $candidates->sortByDesc('seating_capacity')->first();
        }

        return $candidates
            ->sortBy(fn (Bus $bus) => $this->distanceToTrip($bus, $trip) ?? PHP_INT_MAX)
            ->first();
    }

    /**
     * Distance from a candidate's last known position to the trip, in metres.
     */
    private function distanceToTrip(Bus $bus, Trip $trip): ?int
    {
        $driver = Driver::where('assigned_bus_id', $bus->getKey())->first();

        if ($driver?->current_latitude === null || $trip->current_latitude === null) {
            return null;
        }

        $earthRadius = 6_371_000;

        $latFrom = deg2rad((float) $driver->current_latitude);
        $latTo = deg2rad((float) $trip->current_latitude);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad((float) $trip->current_longitude) - deg2rad((float) $driver->current_longitude);

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return (int) round($earthRadius * 2 * asin(min(1.0, sqrt($a))));
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertBusIsUsable(Bus $bus): void
    {
        if (! $bus->status->isOperational()) {
            throw new BusinessRuleException(
                "This bus is {$bus->status->value} and cannot be dispatched as a replacement.",
            );
        }

        $lapsed = $bus->missingOrExpiredDocuments();

        if ($lapsed !== []) {
            $names = implode(', ', array_map(fn ($type) => $type->label(), $lapsed));

            throw new BusinessRuleException(
                "This bus cannot be used — {$names} missing or expired.",
            );
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCanTransition(ReplacementAssignment $assignment, ReplacementStatus $target): void
    {
        if (! $assignment->status->canTransitionTo($target)) {
            throw new BusinessRuleException(
                "A replacement cannot go from {$assignment->status->value} to {$target->value}.",
                ['from' => $assignment->status->value, 'to' => $target->value],
            );
        }
    }
}
