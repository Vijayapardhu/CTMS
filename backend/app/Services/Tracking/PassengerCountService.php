<?php

namespace App\Services\Tracking;

use App\Enums\TripStatus;
use App\Events\Tracking\PassengerBoarded;
use App\Events\Tracking\PassengersLeftBehind;
use App\Exceptions\BusinessRuleException;
use App\Models\PassengerLog;
use App\Models\Student;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The passenger counter (FR-08, BR-254 to BR-256).
 *
 * The capacity rule here is not a soft limit: overloading a bus is illegal and
 * it is what kills people in a crash. Boarding is refused at capacity, and the
 * students left behind are recorded and escalated rather than left in silence.
 */
class PassengerCountService
{
    /**
     * Record a boarding.
     *
     * @throws BusinessRuleException
     */
    public function board(
        Trip $trip,
        User $actor,
        ?Student $student = null,
        ?string $routeStopId = null,
        ?string $idempotencyKey = null,
    ): Trip {
        return DB::transaction(function () use ($trip, $actor, $student, $routeStopId, $idempotencyKey) {
            // Lock the trip: two rapid presses must not both read the same
            // occupancy and both be allowed past capacity.
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            $this->assertTripIsRunning($trip);

            if ($idempotencyKey !== null && $this->alreadyRecorded($trip, $idempotencyKey)) {
                return $trip; // Offline replay; absorbed.
            }

            $capacity = $trip->bus?->seating_capacity ?? 0;

            // BR-254 — refused at capacity, without exception.
            if ($capacity > 0 && $trip->occupied_seat_count >= $capacity) {
                throw new BusinessRuleException(
                    "The bus is full ({$trip->occupied_seat_count}/{$capacity}). This student cannot board.",
                    [
                        'occupied' => $trip->occupied_seat_count,
                        'capacity' => $capacity,
                        'action' => 'record_left_behind',
                    ],
                );
            }

            if ($student !== null && $this->studentAlreadyAboard($trip, $student)) {
                throw new BusinessRuleException('This student is already recorded as aboard.');
            }

            $this->log($trip, 'BOARDED', $actor, $student, $routeStopId, $idempotencyKey);

            $trip->forceFill(['occupied_seat_count' => $trip->occupied_seat_count + 1])->save();

            $this->incrementStopCount($trip, $routeStopId, 'boarded_count');

            // N-04 — the confirmation a guardian is actually waiting for.
            if ($student !== null) {
                PassengerBoarded::dispatch($trip, $student, $routeStopId);
            }

            return $trip->fresh(['bus']);
        });
    }

    /**
     * Record an alighting.
     *
     * @throws BusinessRuleException
     */
    public function alight(
        Trip $trip,
        User $actor,
        ?Student $student = null,
        ?string $routeStopId = null,
        ?string $idempotencyKey = null,
    ): Trip {
        return DB::transaction(function () use ($trip, $actor, $student, $routeStopId, $idempotencyKey) {
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            $this->assertTripIsRunning($trip);

            if ($idempotencyKey !== null && $this->alreadyRecorded($trip, $idempotencyKey)) {
                return $trip;
            }

            // BR-256 — a negative headcount is a data error, not a bus with
            // minus one passenger.
            if ($trip->occupied_seat_count <= 0) {
                throw new BusinessRuleException('The passenger count cannot go below zero.');
            }

            $this->log($trip, 'ALIGHTED', $actor, $student, $routeStopId, $idempotencyKey);

            $trip->forceFill(['occupied_seat_count' => $trip->occupied_seat_count - 1])->save();

            $this->incrementStopCount($trip, $routeStopId, 'alighted_count');

            return $trip->fresh(['bus']);
        });
    }

    /**
     * BR-255 — students who could not board because the bus was full.
     *
     * Silence here is what destroys trust: a student left at a stop with no
     * message assumes they were forgotten, because they were.
     *
     * @param  array<int, string>  $studentIds
     */
    public function recordLeftBehind(Trip $trip, array $studentIds, ?string $routeStopId, User $actor): int
    {
        return DB::transaction(function () use ($trip, $studentIds, $routeStopId) {
            $students = Student::with('user')->whereIn('id', $studentIds)->get();

            if ($students->isEmpty()) {
                return 0;
            }

            PassengersLeftBehind::dispatch($trip, $students->all(), $routeStopId);

            return $students->count();
        });
    }

    /**
     * The manifest for a stop: who is expected, and who has boarded.
     *
     * @return array<string, mixed>
     */
    public function manifestForStop(Trip $trip, string $routeStopId): array
    {
        $expected = Student::with('user')
            ->where('route_id', $trip->route_id)
            ->where('pickup_stop_id', $routeStopId)
            ->get();

        $boardedIds = PassengerLog::where('trip_id', $trip->getKey())
            ->where('action', 'BOARDED')
            ->whereNotNull('student_id')
            ->pluck('student_id')
            ->all();

        return [
            'expected' => $expected->map(fn (Student $student) => [
                'student_id' => (string) $student->getKey(),
                // Name and stop only — a driver needs to know who to expect,
                // not where they live.
                'name' => $student->user?->getFullName(),
                'registration_number' => $student->registration_number,
                'boarded' => in_array((string) $student->getKey(), $boardedIds, true),
            ])->values()->all(),
            'expected_count' => $expected->count(),
            'boarded_count' => count(array_intersect(
                $expected->pluck('id')->map(fn ($id) => (string) $id)->all(),
                $boardedIds,
            )),
        ];
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * @throws BusinessRuleException
     */
    private function assertTripIsRunning(Trip $trip): void
    {
        if ($trip->status !== TripStatus::RUNNING) {
            // BR-257 — attendance freezes when a trip closes.
            throw new BusinessRuleException(
                $trip->status === TripStatus::COMPLETED
                    ? 'This trip has closed. Attendance can no longer be changed here.'
                    : "Passengers can only be counted on a running trip. This trip is {$trip->status->value}.",
            );
        }
    }

    private function alreadyRecorded(Trip $trip, string $idempotencyKey): bool
    {
        return PassengerLog::where('trip_id', $trip->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
    }

    private function studentAlreadyAboard(Trip $trip, Student $student): bool
    {
        $boarded = PassengerLog::where('trip_id', $trip->getKey())
            ->where('student_id', $student->getKey())
            ->where('action', 'BOARDED')
            ->count();

        $alighted = PassengerLog::where('trip_id', $trip->getKey())
            ->where('student_id', $student->getKey())
            ->where('action', 'ALIGHTED')
            ->count();

        return $boarded > $alighted;
    }

    private function log(
        Trip $trip,
        string $action,
        User $actor,
        ?Student $student,
        ?string $routeStopId,
        ?string $idempotencyKey,
    ): void {
        try {
            $entry = new PassengerLog;

            $entry->forceFill([
                'trip_id' => $trip->getKey(),
                'student_id' => $student?->getKey(),
                'route_stop_id' => $routeStopId,
                'recorded_by_id' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'action' => $action,
                'recorded_at' => now(),
                'latitude' => $trip->current_latitude,
                'longitude' => $trip->current_longitude,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Lost an idempotency race; the other write stands.
        }
    }

    private function incrementStopCount(Trip $trip, ?string $routeStopId, string $column): void
    {
        if ($routeStopId === null) {
            return;
        }

        TripStopProgress::where('trip_id', $trip->getKey())
            ->where('route_stop_id', $routeStopId)
            ->increment($column);
    }
}
