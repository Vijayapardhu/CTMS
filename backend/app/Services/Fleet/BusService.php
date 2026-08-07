<?php

namespace App\Services\Fleet;

use App\Enums\BusStatus;
use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Fleet operations on buses (FR-02).
 *
 * All status changes funnel through here so the state machine and the audit
 * trail cannot be bypassed by a controller taking a shortcut.
 */
class BusService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Bus
    {
        return DB::transaction(function () use ($data, $actor) {
            $bus = new Bus($data);

            // A new bus enters the fleet parked, never mid-trip.
            $bus->status = BusStatus::AVAILABLE;
            $bus->save();

            $this->audit->created($bus, $actor);

            return $bus;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Bus $bus, array $data, User $actor): Bus
    {
        return DB::transaction(function () use ($bus, $data, $actor) {
            $before = $bus->getAttributes();

            // Shrinking a bus below the number of passengers already booked on
            // an active trip would silently strand students.
            if (isset($data['seating_capacity'])) {
                $this->assertCapacityChangeIsSafe($bus, (int) $data['seating_capacity']);
            }

            $bus->fill($data);
            $bus->save();

            $this->audit->updated($bus, $before, $actor);

            return $bus;
        });
    }

    /**
     * Move a bus to a new operational status.
     *
     * @throws BusinessRuleException when the transition is not permitted.
     */
    public function changeStatus(Bus $bus, BusStatus $target, User $actor, ?string $reason = null): Bus
    {
        return DB::transaction(function () use ($bus, $target, $actor, $reason) {
            // Re-read under a lock: two admins clicking at once must not both
            // see the old status and both consider their transition legal.
            $bus = Bus::whereKey($bus->getKey())->lockForUpdate()->firstOrFail();

            $current = $bus->status;

            if (! $current->canTransitionTo($target)) {
                throw BusinessRuleException::invalidTransition('bus', $current->value, $target->value);
            }

            if ($current === $target) {
                return $bus; // Idempotent no-op.
            }

            // Taking a bus out of service while it is mid-trip would leave the
            // trip pointing at a vehicle that is no longer on the road.
            if (! $target->isOperational() && $target !== BusStatus::RUNNING && $bus->hasActiveTrip()) {
                throw new BusinessRuleException(
                    'This bus is assigned to an active trip. Reassign or complete the trip first.',
                    ['bus_id' => (string) $bus->getKey()],
                );
            }

            $bus->status = $target;
            $bus->save();

            $this->audit->log(
                action: 'BUS_STATUS_CHANGED',
                table: $bus->getTable(),
                recordId: (string) $bus->getKey(),
                old: ['status' => $current->value],
                new: array_filter([
                    'status' => $target->value,
                    'reason' => $reason,
                ]),
                actor: $actor,
            );

            return $bus;
        });
    }

    /**
     * Retire a bus from the fleet.
     *
     * Soft delete only: trips, incidents and maintenance history all reference
     * this row, and destroying it would orphan the operational record.
     *
     * @throws BusinessRuleException
     */
    public function retire(Bus $bus, User $actor): void
    {
        DB::transaction(function () use ($bus, $actor) {
            $bus = Bus::whereKey($bus->getKey())->lockForUpdate()->firstOrFail();

            if ($bus->hasActiveTrip()) {
                throw new BusinessRuleException(
                    'This bus is assigned to an active trip and cannot be removed.',
                    ['bus_id' => (string) $bus->getKey()],
                );
            }

            if ($bus->status === BusStatus::RUNNING) {
                throw new BusinessRuleException('A bus that is currently running cannot be removed.');
            }

            $bus->status = BusStatus::OFFLINE;
            $bus->save();
            $bus->delete();

            $this->audit->deleted($bus, $actor);
        });
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCapacityChangeIsSafe(Bus $bus, int $newCapacity): void
    {
        $peak = (int) $bus->trips()
            ->whereIn('status', [TripStatus::SCHEDULED->value, TripStatus::RUNNING->value])
            ->max('booked_seat_count');

        if ($peak > $newCapacity) {
            throw new BusinessRuleException(
                "Seating capacity cannot be reduced below {$peak}, the passenger count on an active trip.",
                ['minimum_capacity' => $peak],
            );
        }
    }
}
