<?php

namespace App\Services\Network;

use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Weekly schedule management (FR-05).
 *
 * The invariant: on any given weekday, a bus is in one place at a time and a
 * driver drives one bus at a time. Double-booking either is what strands a
 * busload of students at a stop.
 */
class ScheduleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Schedule
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertResourcesAreUsable($data);

            $schedule = new Schedule($data);

            $this->assertNoConflict($schedule);

            $schedule->is_active = true;
            $schedule->save();

            $this->audit->created($schedule, $actor);

            return $schedule->load(['route', 'bus', 'driver']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Schedule $schedule, array $data, User $actor): Schedule
    {
        return DB::transaction(function () use ($schedule, $data, $actor) {
            $before = $schedule->getAttributes();

            // Only re-check the resources this update actually changes.
            // Re-validating the unchanged route would mean a schedule whose
            // route was later emptied of stops could never be edited again —
            // including to move it onto a route that does have stops.
            $this->assertResourcesAreUsable(
                array_intersect_key($data, array_flip(['route_id', 'bus_id', 'driver_id']))
            );

            $schedule->fill($data);

            $this->assertNoConflict($schedule);

            $schedule->save();

            $this->audit->updated($schedule, $before, $actor);

            return $schedule->load(['route', 'bus', 'driver']);
        });
    }

    /**
     * Switch a schedule on or off without deleting it.
     */
    public function setActive(Schedule $schedule, bool $active, User $actor): Schedule
    {
        return DB::transaction(function () use ($schedule, $active, $actor) {
            $before = $schedule->getAttributes();

            if ($active && ! $schedule->is_active) {
                // Reactivating must re-check for conflicts: the slot may have
                // been taken while this schedule was switched off.
                $this->assertNoConflict($schedule);
            }

            $schedule->is_active = $active;
            $schedule->save();

            $this->audit->updated($schedule, $before, $actor);

            return $schedule;
        });
    }

    public function delete(Schedule $schedule, User $actor): void
    {
        DB::transaction(function () use ($schedule, $actor) {
            if ($schedule->trips()->whereIn('status', ['SCHEDULED', 'RUNNING'])->exists()) {
                throw new BusinessRuleException(
                    'This schedule has trips that have not finished. Cancel them first.',
                );
            }

            $schedule->is_active = false;
            $schedule->save();
            $schedule->delete();

            $this->audit->deleted($schedule, $actor);
        });
    }

    // ========================================================================
    // RULES
    // ========================================================================

    /**
     * The route must be serviceable and the bus and driver must be usable.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    private function assertResourcesAreUsable(array $data): void
    {
        $route = Route::find($data['route_id'] ?? null);

        if ($route && ! $route->isServiceable()) {
            throw new BusinessRuleException(
                "This route is {$route->status->value} and cannot be scheduled.",
            );
        }

        // A route with no stops cannot carry anyone anywhere.
        if ($route && $route->stops()->count() === 0) {
            throw new BusinessRuleException('This route has no stops and cannot be scheduled.');
        }

        $bus = Bus::find($data['bus_id'] ?? null);

        if ($bus && ! $bus->status->isOperational()) {
            throw new BusinessRuleException(
                "This bus is {$bus->status->value} and cannot be scheduled.",
            );
        }

        // BR-055 — scheduling a bus commits it to carrying passengers, so the
        // document check applies here exactly as it does at assignment.
        if ($bus) {
            $lapsed = $bus->missingOrExpiredDocuments();

            if ($lapsed !== []) {
                $names = implode(', ', array_map(fn ($type) => $type->label(), $lapsed));

                throw new BusinessRuleException(
                    "This bus cannot be scheduled — the following documents are missing or expired: {$names}.",
                    ['missing_or_expired_documents' => array_map(fn ($type) => $type->value, $lapsed)],
                );
            }
        }

        $driver = Driver::find($data['driver_id'] ?? null);

        if ($driver && ! $driver->isLicenseValid()) {
            throw new BusinessRuleException('This driver\'s licence has expired.');
        }
    }

    /**
     * Reject a schedule that double-books a bus or a driver.
     *
     * @throws BusinessRuleException
     */
    private function assertNoConflict(Schedule $schedule): void
    {
        $candidates = Schedule::query()
            ->where('day_of_week', $schedule->day_of_week->value)
            ->where('is_active', true)
            ->when($schedule->exists, fn ($q) => $q->whereKeyNot($schedule->getKey()))
            ->where(function ($q) use ($schedule) {
                $q->where('bus_id', $schedule->bus_id)
                    ->orWhere('driver_id', $schedule->driver_id);
            })
            // Half-open interval overlap: two windows clash unless one ends
            // before the other begins.
            ->where('departure_time', '<', $schedule->arrival_time)
            ->where('arrival_time', '>', $schedule->departure_time)
            ->get();

        foreach ($candidates as $other) {
            if (! $this->validityPeriodsOverlap($schedule, $other)) {
                continue; // Different terms of the year; no real clash.
            }

            if ($other->bus_id === $schedule->bus_id) {
                throw new BusinessRuleException(
                    'This bus is already scheduled on another route at that time.',
                    ['conflicting_schedule_id' => (string) $other->getKey()],
                );
            }

            throw new BusinessRuleException(
                'This driver is already scheduled on another route at that time.',
                ['conflicting_schedule_id' => (string) $other->getKey()],
            );
        }
    }

    /**
     * Whether two schedules' start/end date windows intersect at all.
     * A null bound means "open ended" in that direction.
     */
    private function validityPeriodsOverlap(Schedule $a, Schedule $b): bool
    {
        if ($a->start_date && $b->end_date && $a->start_date->gt($b->end_date)) {
            return false;
        }

        if ($b->start_date && $a->end_date && $b->start_date->gt($a->end_date)) {
            return false;
        }

        return true;
    }
}
