<?php

namespace App\Services\Fleet;

use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Events\Fleet\BusAssignedToDriver;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Driver operations (FR-03).
 */
class DriverService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Attach a driver profile to an existing user account.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, User $actor): Driver
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::find($data['user_id']);

            if (! $user || ! $user->hasRole(UserRole::DRIVER)) {
                throw new BusinessRuleException('A driver profile can only be attached to a driver account.');
            }

            if ($user->driver()->exists()) {
                throw new BusinessRuleException('This account already has a driver profile.');
            }

            $driver = new Driver($data);
            $driver->status = DriverStatus::AVAILABLE;
            $driver->save();

            $this->audit->created($driver, $actor);

            return $driver->load('user');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Driver $driver, array $data, User $actor): Driver
    {
        return DB::transaction(function () use ($driver, $data, $actor) {
            $before = $driver->getAttributes();

            $driver->fill($data);
            $driver->save();

            $this->audit->updated($driver, $before, $actor);

            return $driver->load('user');
        });
    }

    /**
     * Move a driver to a new duty status.
     *
     * @throws BusinessRuleException
     */
    public function changeStatus(Driver $driver, DriverStatus $target, User $actor): Driver
    {
        return DB::transaction(function () use ($driver, $target, $actor) {
            $driver = Driver::whereKey($driver->getKey())->lockForUpdate()->firstOrFail();

            $current = $driver->status;

            if (! $current->canTransitionTo($target)) {
                throw BusinessRuleException::invalidTransition('driver', $current->value, $target->value);
            }

            if ($current === $target) {
                return $driver;
            }

            // Sending a driver off duty or on leave mid-trip would leave a bus
            // full of students with nobody responsible for it.
            if ($target !== DriverStatus::AVAILABLE && $target !== DriverStatus::ON_TRIP && $driver->hasActiveTrip()) {
                throw new BusinessRuleException(
                    'This driver is assigned to an active trip. Reassign or complete the trip first.',
                );
            }

            // A driver cannot start a trip on a lapsed licence.
            if ($target === DriverStatus::ON_TRIP && ! $driver->isLicenseValid()) {
                throw new BusinessRuleException('This driver\'s licence has expired.');
            }

            $driver->status = $target;
            $driver->save();

            $this->audit->log(
                action: 'DRIVER_STATUS_CHANGED',
                table: $driver->getTable(),
                recordId: (string) $driver->getKey(),
                old: ['status' => $current->value],
                new: ['status' => $target->value],
                actor: $actor,
            );

            return $driver->load('user');
        });
    }

    /**
     * Assign a bus to a driver.
     *
     * @throws BusinessRuleException
     */
    public function assignBus(Driver $driver, Bus $bus, User $actor): Driver
    {
        return DB::transaction(function () use ($driver, $bus, $actor) {
            // Lock both rows before deciding anything, so a concurrent request
            // cannot read the same "free" bus and assign it twice.
            $driver = Driver::whereKey($driver->getKey())->lockForUpdate()->firstOrFail();
            $bus = Bus::whereKey($bus->getKey())->lockForUpdate()->firstOrFail();

            if (! $driver->isLicenseValid()) {
                throw new BusinessRuleException('This driver\'s licence has expired and cannot be assigned a bus.');
            }

            if ($driver->status === DriverStatus::LEAVE || $driver->status === DriverStatus::OFF_DUTY) {
                throw new BusinessRuleException('This driver is not on duty.');
            }

            if (! $bus->status->isOperational()) {
                throw new BusinessRuleException(
                    "This bus is {$bus->status->value} and cannot be assigned to a driver.",
                    ['bus_status' => $bus->status->value],
                );
            }

            // BR-055 — a legal bar, with no override. Operating on a lapsed
            // certificate voids insurance cover for every passenger aboard.
            $lapsed = $bus->missingOrExpiredDocuments();

            if ($lapsed !== []) {
                $names = implode(', ', array_map(fn ($type) => $type->label(), $lapsed));

                throw new BusinessRuleException(
                    "This bus cannot be used — the following documents are missing or expired: {$names}.",
                    ['missing_or_expired_documents' => array_map(fn ($type) => $type->value, $lapsed)],
                );
            }

            $existing = Driver::where('assigned_bus_id', $bus->getKey())->first();

            if ($existing && ! $existing->is($driver)) {
                throw new BusinessRuleException(
                    'This bus is already assigned to another driver.',
                    ['bus_id' => (string) $bus->getKey()],
                );
            }

            $before = $driver->getAttributes();

            $driver->assigned_bus_id = $bus->getKey();
            // Kept in step for reporting; the foreign key is the source of truth.
            $driver->vehicle_registration = $bus->registration_number;

            try {
                $driver->save();
            } catch (QueryException $e) {
                // The unique index is the last line of defence if two requests
                // slipped past the check above on a database without row locks.
                throw new BusinessRuleException('This bus is already assigned to another driver.');
            }

            $this->audit->updated($driver, $before, $actor);

            BusAssignedToDriver::dispatch($driver, $bus);

            return $driver->load(['user', 'assignedBus']);
        });
    }

    /**
     * Release the driver's bus.
     */
    public function unassignBus(Driver $driver, User $actor): Driver
    {
        return DB::transaction(function () use ($driver, $actor) {
            $driver = Driver::whereKey($driver->getKey())->lockForUpdate()->firstOrFail();

            if ($driver->hasActiveTrip()) {
                throw new BusinessRuleException(
                    'This driver is on an active trip and cannot be unassigned from their bus.',
                );
            }

            $before = $driver->getAttributes();

            $driver->assigned_bus_id = null;
            $driver->vehicle_registration = null;
            $driver->save();

            $this->audit->updated($driver, $before, $actor);

            return $driver->load('user');
        });
    }

    /**
     * Remove a driver from the roster.
     *
     * @throws BusinessRuleException
     */
    public function retire(Driver $driver, User $actor): void
    {
        DB::transaction(function () use ($driver, $actor) {
            $driver = Driver::whereKey($driver->getKey())->lockForUpdate()->firstOrFail();

            if ($driver->hasActiveTrip()) {
                throw new BusinessRuleException('This driver is assigned to an active trip and cannot be removed.');
            }

            // Free the bus so it can be given to someone else.
            $driver->assigned_bus_id = null;
            $driver->vehicle_registration = null;
            $driver->status = DriverStatus::OFF_DUTY;
            $driver->save();
            $driver->delete();

            $this->audit->deleted($driver, $actor);
        });
    }
}
