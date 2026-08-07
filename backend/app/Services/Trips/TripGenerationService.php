<?php

namespace App\Services\Trips;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Models\Schedule;
use App\Models\ServiceCalendarDay;
use App\Models\Trip;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * BG-01 — turns the weekly timetable into concrete trips for a date.
 *
 * Runs nightly and can be re-run by hand at any time: BR-263 makes it
 * idempotent per (schedule, date), enforced by a unique index, so a second run
 * creates nothing rather than doubling the day.
 *
 * Exceptions are surfaced, never silently resolved (BG-02). A schedule whose
 * driver is on leave produces a trip *and* an exception, because operations
 * need to see the gap before 06:30, not discover it when the bus does not
 * arrive.
 */
class TripGenerationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Generate the day's trips.
     *
     * @return array{
     *     date: string,
     *     created: int,
     *     skipped: int,
     *     suspended: bool,
     *     reason: string|null,
     *     exceptions: array<int, array<string, mixed>>
     * }
     */
    public function generateFor(CarbonInterface $date, ?User $actor = null): array
    {
        $suspension = ServiceCalendarDay::suspensionOn($date);

        if ($suspension !== null) {
            // BR-264 — nothing is generated, and the reason is recorded so the
            // absence of trips is explicable rather than alarming.
            $this->audit->log(
                action: 'TRIP_GENERATION_SKIPPED',
                table: 'trips',
                new: [
                    'date' => $date->toDateString(),
                    'reason' => $suspension->reason,
                    'day_type' => $suspension->day_type->value,
                ],
                actor: $actor,
            );

            return [
                'date' => $date->toDateString(),
                'created' => 0,
                'skipped' => 0,
                'suspended' => true,
                'reason' => $suspension->reason,
                'exceptions' => [],
            ];
        }

        $created = 0;
        $skipped = 0;
        $exceptions = [];

        $schedules = Schedule::with(['route', 'bus', 'driver'])
            ->where('is_active', true)
            ->get();

        foreach ($schedules as $schedule) {
            if (! $schedule->runsOn($date)) {
                continue;
            }

            $trip = $this->createTrip($schedule, $date);

            if ($trip === null) {
                $skipped++; // Already generated; the unique index absorbed it.

                continue;
            }

            $created++;

            foreach ($this->exceptionsFor($schedule, $trip) as $exception) {
                $exceptions[] = $exception;
            }
        }

        $this->audit->log(
            action: 'TRIPS_GENERATED',
            table: 'trips',
            new: [
                'date' => $date->toDateString(),
                'created' => $created,
                'skipped' => $skipped,
                'exception_count' => count($exceptions),
            ],
            actor: $actor,
        );

        return [
            'date' => $date->toDateString(),
            'created' => $created,
            'skipped' => $skipped,
            'suspended' => false,
            'reason' => null,
            'exceptions' => $exceptions,
        ];
    }

    /**
     * @return Trip|null Null when a trip already exists for this slot.
     */
    private function createTrip(Schedule $schedule, CarbonInterface $date): ?Trip
    {
        try {
            return DB::transaction(function () use ($schedule, $date) {
                $trip = new Trip([
                    'schedule_id' => $schedule->getKey(),
                    'bus_id' => $schedule->bus_id,
                    'driver_id' => $schedule->driver_id,
                    'route_id' => $schedule->route_id,
                    'trip_date' => $date->toDateString(),
                    'scheduled_departure_time' => $schedule->departure_time,
                    'scheduled_arrival_time' => $schedule->arrival_time,
                    // The manifest is built from current assignments; absence
                    // handling arrives with attendance in 4B.
                    'booked_seat_count' => $schedule->route?->assignedStudentCount() ?? 0,
                ]);

                $trip->status = TripStatus::SCHEDULED;
                $trip->generated_at = now();
                $trip->save();

                return $trip;
            });
        } catch (UniqueConstraintViolationException) {
            // BR-263 — idempotent by construction.
            return null;
        }
    }

    /**
     * BG-02 — everything about this trip that needs a human before the day
     * starts. Surfaced, not resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    private function exceptionsFor(Schedule $schedule, Trip $trip): array
    {
        $exceptions = [];

        $add = function (string $type, string $message, bool $blocking) use (&$exceptions, $trip) {
            $exceptions[] = [
                'trip_id' => (string) $trip->getKey(),
                'type' => $type,
                'message' => $message,
                'blocking' => $blocking,
            ];
        };

        $bus = $schedule->bus;
        $driver = $schedule->driver;

        if ($bus === null) {
            $add('NO_BUS', 'This schedule has no bus assigned.', true);
        } else {
            if (! $bus->status->isOperational()) {
                $add('BUS_UNAVAILABLE', "Bus {$bus->registration_number} is {$bus->status->value}.", true);
            }

            foreach ($bus->missingOrExpiredDocuments() as $type) {
                $add('DOCUMENT_EXPIRED',
                    "Bus {$bus->registration_number}: {$type->label()} is missing or expired.", true);
            }
        }

        if ($driver === null) {
            $add('UNSTAFFED', 'This schedule has no driver assigned.', true);
        } elseif (! $driver->isLicenseValid()) {
            $add('LICENCE_EXPIRED', 'The assigned driver\'s licence has expired.', true);
        } elseif (! $driver->status->isAssignable() && ! $driver->status->canTransitionTo(DriverStatus::ON_TRIP)) {
            $add('DRIVER_UNAVAILABLE', "The assigned driver is {$driver->status->value}.", true);
        }

        if ($trip->booked_seat_count === 0) {
            $add('NO_PASSENGERS', 'No students are assigned to this route.', false);
        }

        return $exceptions;
    }
}
