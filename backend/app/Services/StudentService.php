<?php

namespace App\Services;

use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Events\People\StudentTransportAssigned;
use App\Events\People\StudentTransportCleared;
use App\Exceptions\BusinessRuleException;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Student records and transport assignment (FR-04).
 */
class StudentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function create(array $data, User $actor): Student
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::find($data['user_id']);

            if (! $user || ! $user->hasRole(UserRole::STUDENT)) {
                throw new BusinessRuleException('A student profile can only be attached to a student account.');
            }

            if ($user->student()->exists()) {
                throw new BusinessRuleException('This account already has a student profile.');
            }

            $student = new Student($data);
            $student->status = StudentStatus::ACTIVE;
            $student->save();

            $this->audit->created($student, $actor);

            return $student->load('user');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data, User $actor): Student
    {
        return DB::transaction(function () use ($student, $data, $actor) {
            $before = $student->getAttributes();

            $student->fill($data);
            $student->save();

            $this->audit->updated($student, $before, $actor);

            return $student->load('user');
        });
    }

    /**
     * Seat a student on a route.
     *
     * @throws BusinessRuleException
     */
    public function assignTransport(
        Student $student,
        Route $route,
        RouteStop $pickup,
        ?RouteStop $dropoff,
        User $actor,
        ?string $capacityOverrideReason = null,
    ): Student {
        return DB::transaction(function () use ($student, $route, $pickup, $dropoff, $actor, $capacityOverrideReason) {
            $student = Student::whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            // Lock the route too: two concurrent assignments must not both read
            // the same remaining seat and both take it (BR-159).
            $route = Route::whereKey($route->getKey())->lockForUpdate()->firstOrFail();

            if (! $student->status->isEligibleForTransport()) {
                throw new BusinessRuleException(
                    "This student's record is {$student->status->value} and cannot be assigned transport.",
                );
            }

            if (! $student->hasActiveTicket()) {
                throw new BusinessRuleException('This student does not hold a valid transport ticket.');
            }

            if (! $route->isServiceable()) {
                throw new BusinessRuleException(
                    "This route is {$route->status->value} and cannot take passengers.",
                );
            }

            // A stop id that belongs to a different route would seat the
            // student at a place their bus never visits.
            if ($pickup->route_id !== $route->getKey()) {
                throw new BusinessRuleException('The pickup stop does not belong to the selected route.');
            }

            if (! $pickup->stop_type->allowsPickup()) {
                throw new BusinessRuleException('The selected stop is not a pickup point.');
            }

            if ($dropoff !== null) {
                if ($dropoff->route_id !== $route->getKey()) {
                    throw new BusinessRuleException('The drop-off stop does not belong to the selected route.');
                }

                if (! $dropoff->stop_type->allowsDropoff()) {
                    throw new BusinessRuleException('The selected stop is not a drop-off point.');
                }

                if ($dropoff->is($pickup)) {
                    throw new BusinessRuleException('The pickup and drop-off stops must be different.');
                }
            }

            // BR-159 / BR-160 — capacity, with an explicit reasoned override.
            // Re-assigning a student already on this route consumes no new seat.
            $alreadyOnThisRoute = $student->route_id === $route->getKey();

            if (! $alreadyOnThisRoute) {
                $this->assertRouteHasCapacity($route, $capacityOverrideReason);
            }

            $before = $student->getAttributes();

            // Assignment columns are not fillable, so they are set explicitly.
            $student->forceFill([
                'route_id' => $route->getKey(),
                'pickup_stop_id' => $pickup->getKey(),
                'dropoff_stop_id' => $dropoff?->getKey(),
                'transport_assigned_at' => now(),
            ])->save();

            $this->audit->updated($student, $before, $actor);

            // An override is a deliberate departure from policy and is recorded
            // separately from the assignment itself, so it can be reported on.
            if (! $alreadyOnThisRoute && $capacityOverrideReason !== null) {
                $this->audit->log(
                    action: 'ROUTE_CAPACITY_OVERRIDDEN',
                    table: $route->getTable(),
                    recordId: (string) $route->getKey(),
                    new: [
                        'student_id' => (string) $student->getKey(),
                        'assignable_capacity' => $route->assignableCapacity(),
                        'assigned_after' => $route->assignedStudentCount(),
                        'reason' => $capacityOverrideReason,
                    ],
                    actor: $actor,
                );
            }

            // The module publishes what happened; it knows nothing about who
            // gets told or how (BR-213, N-25).
            StudentTransportAssigned::dispatch(
                $student,
                $route,
                $pickup,
                $before['route_id'] !== null,
            );

            return $student->load(['user', 'route', 'pickupStop', 'dropoffStop']);
        });
    }

    /**
     * BR-159 — a route must not be assigned beyond the capacity of the
     * smallest bus scheduled on it, less the safety margin.
     *
     * BR-160 — exceeding it is sometimes the right operational call, but never
     * an accident: it requires a stated reason, which is audited.
     *
     * @throws BusinessRuleException
     */
    private function assertRouteHasCapacity(Route $route, ?string $overrideReason): void
    {
        $capacity = $route->assignableCapacity();

        if ($capacity === null) {
            return; // No active schedule yet — nothing to measure against.
        }

        $assigned = $route->assignedStudentCount();

        if ($assigned < $capacity) {
            return;
        }

        if ($overrideReason !== null && trim($overrideReason) !== '') {
            return; // Deliberate override; recorded by the caller.
        }

        throw new BusinessRuleException(
            "This route is at capacity ({$assigned}/{$capacity}).",
            [
                'assigned' => $assigned,
                'assignable_capacity' => $capacity,
                'override_field' => 'capacity_override_reason',
            ],
        );
    }

    /**
     * Remove a student's transport assignment.
     */
    public function clearTransport(Student $student, User $actor): Student
    {
        return DB::transaction(function () use ($student, $actor) {
            $before = $student->getAttributes();

            $hadTransport = $before['route_id'] !== null;

            $student->forceFill([
                'route_id' => null,
                'pickup_stop_id' => null,
                'dropoff_stop_id' => null,
                'transport_assigned_at' => null,
            ])->save();

            $this->audit->updated($student, $before, $actor);

            if ($hadTransport) {
                StudentTransportCleared::dispatch($student);
            }

            return $student;
        });
    }

    /**
     * Change a student record's status.
     *
     * Suspending a student also removes their seat: leaving them assigned
     * would keep them counted in occupancy planning for trips they may not board.
     */
    public function changeStatus(Student $student, StudentStatus $target, User $actor): Student
    {
        return DB::transaction(function () use ($student, $target, $actor) {
            $student = Student::whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            $before = $student->getAttributes();
            $current = $student->status;

            if ($current === $target) {
                return $student;
            }

            $student->status = $target;

            if (! $target->isEligibleForTransport()) {
                $student->forceFill([
                    'route_id' => null,
                    'pickup_stop_id' => null,
                    'dropoff_stop_id' => null,
                    'transport_assigned_at' => null,
                ]);
            }

            $student->save();

            $this->audit->log(
                action: 'STUDENT_STATUS_CHANGED',
                table: $student->getTable(),
                recordId: (string) $student->getKey(),
                old: ['status' => $current->value],
                new: ['status' => $target->value],
                actor: $actor,
            );

            return $student;
        });
    }

    public function delete(Student $student, User $actor): void
    {
        DB::transaction(function () use ($student, $actor) {
            $student->delete();

            $this->audit->deleted($student, $actor);
        });
    }
}
