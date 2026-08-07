<?php

namespace App\Services\Trips;

use App\Enums\BusStatus;
use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Events\Trips\TripCancelled;
use App\Events\Trips\TripCompleted;
use App\Events\Trips\TripStarted;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\ServiceCalendarDay;
use App\Models\Trip;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fleet\DutyHoursService;
use App\Services\Fleet\VehicleInspectionService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Tracking\GeofenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The trip lifecycle (FR-06).
 *
 * This service orchestrates trips and publishes domain events. It contains no
 * notification logic of any kind: who gets told that a trip started is decided
 * by the event class and delivered by the notification platform.
 */
class TripService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VehicleInspectionService $inspections,
        private readonly GeofenceService $geofences,
        private readonly MaintenanceService $maintenance,
        private readonly DutyHoursService $dutyHours,
    ) {}

    /**
     * Create a trip outside the timetable — a field visit, an extra evening run.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function createAdHoc(array $data, User $actor, ?string $overrideReason = null): Trip
    {
        return DB::transaction(function () use ($data, $actor, $overrideReason) {
            $date = Carbon::parse($data['trip_date']);

            // BR-265 — a trip on a non-operating day is sometimes right, never
            // accidental.
            $suspension = ServiceCalendarDay::suspensionOn($date);

            if ($suspension !== null && blank($overrideReason)) {
                throw new BusinessRuleException(
                    "{$date->toDateString()} is not an operating day ({$suspension->reason}).",
                    ['requires' => 'override_reason'],
                );
            }

            // `override_reason` arrives in the payload but is not a fillable
            // trip attribute — it is an audit fact about how this trip came to
            // exist, set explicitly below.
            $trip = new Trip(array_diff_key($data, ['override_reason' => null]));
            $trip->status = TripStatus::SCHEDULED;
            $trip->override_reason = $overrideReason;
            $trip->save();

            $this->audit->created($trip, $actor);

            return $trip->load(['route', 'bus', 'driver']);
        });
    }

    /**
     * Start a trip (BR-251, BR-252, BR-253).
     *
     * @throws BusinessRuleException
     */
    public function start(Trip $trip, User $actor): Trip
    {
        return DB::transaction(function () use ($trip, $actor) {
            // Lock the trip, the bus and the driver together: two devices
            // pressing Start at once must not both pass the gate.
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($trip, TripStatus::RUNNING);
            $this->assertActorMayOperate($trip, $actor);

            // BR-252 — not before the window opens.
            if (! $trip->isWithinStartWindow()) {
                throw new BusinessRuleException(
                    'This trip cannot start until '.$trip->scheduledDepartureAt()
                        ->subMinutes((int) config('ctms.trip.checkin_window_minutes', 15))
                        ->format('H:i').'.',
                    ['scheduled_departure' => $trip->scheduledDepartureAt()->toIso8601String()],
                );
            }

            $bus = Bus::whereKey($trip->bus_id)->lockForUpdate()->first();
            $driver = Driver::whereKey($trip->driver_id)->lockForUpdate()->first();

            if ($bus === null || $driver === null) {
                throw new BusinessRuleException('This trip has no bus or driver assigned.');
            }

            // BR-251 — the composite safety gate. Bus status, statutory
            // documents and today's inspection, in one call, reporting every
            // blocking reason rather than the first.
            $this->inspections->assertClearedForService($bus);

            // BR-366 — a bus past the grace period on a scheduled service does
            // not go out. The grace exists so a service falling due does not
            // cancel a route on the day; running indefinitely past it is what
            // the rule exists to stop.
            $this->maintenance->assertNotBlockedByPreventiveMaintenance($bus);

            if (! $driver->isLicenseValid()) {
                throw new BusinessRuleException('This driver\'s licence has expired.');
            }

            if ($driver->hasActiveTrip() && ! $driver->trips()
                ->whereKey($trip->getKey())->exists()) {
                throw new BusinessRuleException('This driver is already on an active trip.');
            }

            // BR-106 — duty-hour ceilings. Checked against trips actually
            // driven today, so a roster gap cannot quietly put a driver over
            // their limit.
            $this->dutyHours->assertWithinDutyLimits($driver);

            // BR-109 — a driver stood down after a critical incident, or on
            // leave, does not take a bus out. Reassignment already checked
            // this; starting a trip did not, which meant standing a driver
            // down had no teeth against the one route that matters.
            if (! $driver->status->isAssignable() && $driver->status !== DriverStatus::ON_TRIP) {
                throw new BusinessRuleException(
                    "This driver is {$driver->status->value} and cannot take a bus out.",
                    ['driver_status' => $driver->status->value],
                );
            }

            $trip->forceFill([
                'status' => TripStatus::RUNNING,
                'actual_departure_time' => now()->format('H:i:s'),
                'started_by_id' => $actor->getKey(),
            ])->save();

            $bus->status = BusStatus::RUNNING;
            $bus->save();

            $driver->status = DriverStatus::ON_TRIP;
            $driver->save();

            $this->audit->log(
                action: 'TRIP_STARTED',
                table: $trip->getTable(),
                recordId: (string) $trip->getKey(),
                old: ['status' => TripStatus::SCHEDULED->value],
                new: [
                    'status' => TripStatus::RUNNING->value,
                    'actual_departure_time' => $trip->actual_departure_time,
                    'delay_minutes' => $trip->delayMinutes(),
                ],
                actor: $actor,
            );

            $trip = $trip->fresh(['route', 'bus', 'driver']);

            // The geofence state machine needs a row per stop before the first
            // position arrives, or the opening readings are evaluated against
            // nothing.
            $this->geofences->initialiseFor($trip);

            TripStarted::dispatch($trip);

            return $trip;
        });
    }

    /**
     * Complete a trip.
     *
     * @throws BusinessRuleException
     */
    public function complete(Trip $trip, User $actor, ?string $notes = null): Trip
    {
        return $this->close($trip, $actor, autoClosed: false, notes: $notes);
    }

    /**
     * BR-260 — close a trip the driver left running past its arrival buffer.
     *
     * Flagged as auto-closed so reports can tell it apart from a trip that
     * finished properly (BR-261).
     */
    public function autoClose(Trip $trip): Trip
    {
        return $this->close($trip, actor: null, autoClosed: true,
            notes: 'Closed automatically: still running past the scheduled arrival buffer.');
    }

    /**
     * Cancel a trip (BR-262).
     *
     * @throws BusinessRuleException
     */
    public function cancel(Trip $trip, string $reason, User $actor): Trip
    {
        return DB::transaction(function () use ($trip, $reason, $actor) {
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($trip, TripStatus::CANCELLED);

            $wasRunning = $trip->isRunning();
            $previous = $trip->status;

            $trip->forceFill([
                'status' => TripStatus::CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by_id' => $actor->getKey(),
                'cancelled_at' => now(),
            ])->save();

            $this->releaseResources($trip, $wasRunning);

            $this->audit->log(
                action: 'TRIP_CANCELLED',
                table: $trip->getTable(),
                recordId: (string) $trip->getKey(),
                old: ['status' => $previous->value],
                new: ['status' => TripStatus::CANCELLED->value, 'reason' => $reason],
                actor: $actor,
            );

            $trip = $trip->fresh(['route', 'bus', 'driver']);

            TripCancelled::dispatch($trip, $reason, $wasRunning);

            return $trip;
        });
    }

    /**
     * Reassign the bus, the driver, or both (BR-267).
     *
     * @throws BusinessRuleException
     */
    public function reassign(Trip $trip, ?Bus $bus, ?Driver $driver, User $actor, string $reason): Trip
    {
        return DB::transaction(function () use ($trip, $bus, $driver, $actor, $reason) {
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            if ($trip->isTerminal()) {
                throw new BusinessRuleException(
                    "This trip is {$trip->status->value} and can no longer be changed.",
                );
            }

            $before = $trip->getAttributes();

            // BR-267 — eligibility is re-checked at commit, not when the page
            // was loaded. The candidate may have become unavailable since.
            if ($bus !== null) {
                $this->assertBusIsEligible($bus, $trip);
                $trip->bus_id = $bus->getKey();
            }

            if ($driver !== null) {
                $this->assertDriverIsEligible($driver, $trip);
                $trip->driver_id = $driver->getKey();
            }

            $trip->save();

            $this->audit->log(
                action: 'TRIP_REASSIGNED',
                table: $trip->getTable(),
                recordId: (string) $trip->getKey(),
                old: array_intersect_key($before, array_flip(['bus_id', 'driver_id'])),
                new: [
                    'bus_id' => (string) $trip->bus_id,
                    'driver_id' => (string) $trip->driver_id,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $trip->fresh(['route', 'bus', 'driver']);
        });
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    private function close(Trip $trip, ?User $actor, bool $autoClosed, ?string $notes): Trip
    {
        return DB::transaction(function () use ($trip, $actor, $autoClosed, $notes) {
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($trip, TripStatus::COMPLETED);

            if ($actor !== null) {
                $this->assertActorMayOperate($trip, $actor);
            }

            $trip->forceFill([
                'status' => TripStatus::COMPLETED,
                'actual_arrival_time' => now()->format('H:i:s'),
                'ended_by_id' => $actor?->getKey(),
                'auto_closed' => $autoClosed,
            ])->save();

            $this->releaseResources($trip, wasRunning: true);

            $this->audit->log(
                action: $autoClosed ? 'TRIP_AUTO_CLOSED' : 'TRIP_COMPLETED',
                table: $trip->getTable(),
                recordId: (string) $trip->getKey(),
                old: ['status' => TripStatus::RUNNING->value],
                new: [
                    'status' => TripStatus::COMPLETED->value,
                    'auto_closed' => $autoClosed,
                    'notes' => $notes,
                ],
                actor: $actor,
            );

            $trip = $trip->fresh(['route', 'bus', 'driver']);

            TripCompleted::dispatch($trip, $autoClosed);

            return $trip;
        });
    }

    /**
     * Return the bus and driver to the pool when a trip leaves the road.
     */
    private function releaseResources(Trip $trip, bool $wasRunning): void
    {
        if (! $wasRunning) {
            return; // A scheduled trip never took them out of the pool.
        }

        $bus = Bus::find($trip->bus_id);

        if ($bus !== null && $bus->status === BusStatus::RUNNING) {
            $bus->status = BusStatus::AVAILABLE;
            $bus->save();
        }

        $driver = Driver::find($trip->driver_id);

        if ($driver !== null && $driver->status === DriverStatus::ON_TRIP) {
            $driver->status = DriverStatus::AVAILABLE;
            $driver->save();
        }
    }

    /**
     * BR-250 — forward only, and terminal states never reopen.
     *
     * @throws BusinessRuleException
     */
    private function assertCanTransition(Trip $trip, TripStatus $target): void
    {
        if (! $trip->status->canTransitionTo($target)) {
            throw new BusinessRuleException(
                $trip->isTerminal()
                    ? "This trip is {$trip->status->value} and can no longer be changed."
                    : "A trip cannot go from {$trip->status->value} to {$target->value}.",
                ['from' => $trip->status->value, 'to' => $target->value],
            );
        }
    }

    /**
     * BR-253 — only the assigned driver or an administrator operates a trip.
     *
     * @throws BusinessRuleException
     */
    private function assertActorMayOperate(Trip $trip, User $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $isAssignedDriver = $actor->isDriver()
            && $actor->driver !== null
            && (string) $actor->driver->getKey() === (string) $trip->driver_id;

        if (! $isAssignedDriver) {
            throw new BusinessRuleException(
                'Only the assigned driver or the transport office can do this.',
            );
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertBusIsEligible(Bus $bus, Trip $trip): void
    {
        if (! $bus->status->isOperational() && (string) $bus->getKey() !== (string) $trip->bus_id) {
            throw new BusinessRuleException(
                "Bus {$bus->registration_number} is {$bus->status->value} and is no longer available.",
            );
        }

        $lapsed = $bus->missingOrExpiredDocuments();

        if ($lapsed !== []) {
            $names = implode(', ', array_map(fn ($type) => $type->label(), $lapsed));

            throw new BusinessRuleException(
                "Bus {$bus->registration_number} cannot be used — {$names} missing or expired.",
            );
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertDriverIsEligible(Driver $driver, Trip $trip): void
    {
        if (! $driver->isLicenseValid()) {
            throw new BusinessRuleException('That driver\'s licence has expired.');
        }

        $isCurrentDriver = (string) $driver->getKey() === (string) $trip->driver_id;

        if (! $isCurrentDriver && $driver->hasActiveTrip()) {
            throw new BusinessRuleException('That driver is already on an active trip.');
        }

        if (! $isCurrentDriver && ! $driver->status->isAssignable()) {
            throw new BusinessRuleException("That driver is {$driver->status->value} and is no longer available.");
        }
    }
}
