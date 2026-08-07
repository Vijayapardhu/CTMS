<?php

namespace App\Services\Network;

use App\Enums\RouteStatus;
use App\Events\Network\RouteChanged;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Route and stop management (FR-05).
 *
 * The invariant this class defends: a route's stops form a contiguous
 * 1..N sequence with no gaps and no duplicates. Everything downstream — ETA,
 * geofencing, "next stop" — assumes that ordering is meaningful.
 */
class RouteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Route
    {
        return DB::transaction(function () use ($data, $actor) {
            $route = new Route($data);
            $route->status = RouteStatus::ACTIVE;
            $route->number_of_stops = 0;
            $route->save();

            $this->audit->created($route, $actor);

            return $route;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Route $route, array $data, User $actor): Route
    {
        [$route, $changed] = DB::transaction(function () use ($route, $data, $actor) {
            $before = $route->getAttributes();

            $route->fill($data);

            // Captured before the save, which is the only moment it can be.
            $changed = array_keys($route->getDirty());

            $route->save();

            $this->audit->updated($route, $before, $actor);

            return [$route, $changed];
        });

        // BR-213, N-27 — the riders assigned to this route have to be told.
        // The event class existed from the start and nothing ever dispatched
        // it, so a route could be re-timed or renamed and every student on it
        // would find out by standing at a stop.
        //
        // Outside the transaction, like every other publisher here: a
        // notification failure must not roll back the change (BR-408).
        if ($changed !== []) {
            RouteChanged::dispatch($route->fresh(), $changed);
        }

        return $route;
    }

    /**
     * Change a route's service status.
     */
    public function changeStatus(Route $route, RouteStatus $target, User $actor): Route
    {
        return DB::transaction(function () use ($route, $target, $actor) {
            $route = Route::whereKey($route->getKey())->lockForUpdate()->firstOrFail();

            $current = $route->status;

            if ($current === $target) {
                return $route;
            }

            $route->status = $target;
            $route->save();

            $this->audit->log(
                action: 'ROUTE_STATUS_CHANGED',
                table: $route->getTable(),
                recordId: (string) $route->getKey(),
                old: ['status' => $current->value],
                new: ['status' => $target->value],
                actor: $actor,
            );

            return $route;
        });
    }

    /**
     * Append or insert a stop.
     *
     * When `sequence_number` is supplied, the stop is inserted at that position
     * and everything at or after it shifts down. Otherwise it is appended.
     *
     * @param  array<string, mixed>  $data
     */
    public function addStop(Route $route, array $data, User $actor): RouteStop
    {
        return DB::transaction(function () use ($route, $data, $actor) {
            // Lock the route row so two concurrent inserts cannot both compute
            // the same "next" sequence number.
            $route = Route::whereKey($route->getKey())->lockForUpdate()->firstOrFail();

            $existingCount = $route->stops()->count();
            $requested = isset($data['sequence_number']) ? (int) $data['sequence_number'] : null;

            // A stop may be appended at the end, but not dropped into a gap
            // beyond it — that would break the contiguous sequence.
            $position = $requested !== null
                ? min(max($requested, 1), $existingCount + 1)
                : $existingCount + 1;

            $this->shiftStopsFrom($route, $position, by: 1);

            $stop = new RouteStop(array_diff_key($data, ['sequence_number' => null]));
            $stop->route_id = $route->getKey();
            $stop->sequence_number = $position;
            $stop->save();

            $route->syncStopCount();

            $this->audit->created($stop, $actor);

            return $stop;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStop(Route $route, string $stopId, array $data, User $actor): RouteStop
    {
        return DB::transaction(function () use ($route, $stopId, $data, $actor) {
            $route = Route::whereKey($route->getKey())->lockForUpdate()->firstOrFail();
            $stop = $this->findStopOnRoute($route, $stopId);

            $before = $stop->getAttributes();

            if (isset($data['sequence_number'])) {
                $this->moveStop($route, $stop, (int) $data['sequence_number']);
                unset($data['sequence_number']);
            }

            $stop->fill($data);
            $stop->save();

            $this->audit->updated($stop, $before, $actor);

            return $stop->refresh();
        });
    }

    /**
     * Remove a stop and close the gap it leaves behind.
     */
    public function deleteStop(Route $route, string $stopId, User $actor): void
    {
        DB::transaction(function () use ($route, $stopId, $actor) {
            $route = Route::whereKey($route->getKey())->lockForUpdate()->firstOrFail();
            $stop = $this->findStopOnRoute($route, $stopId);

            // Students boarding here would be left without a pickup point.
            $ridersAffected = $stop->pickupStudents()->count() + $stop->dropoffStudents()->count();

            if ($ridersAffected > 0) {
                throw new BusinessRuleException(
                    "This stop is assigned to {$ridersAffected} student(s). Reassign them before removing it.",
                    ['students_affected' => $ridersAffected],
                );
            }

            $position = $stop->sequence_number;

            $stop->delete();

            // Pull the remaining stops up so the sequence stays 1..N.
            $this->shiftStopsFrom($route, $position + 1, by: -1);

            $route->syncStopCount();

            $this->audit->deleted($stop, $actor);
        });
    }

    /**
     * Retire a route.
     */
    public function retire(Route $route, User $actor): void
    {
        DB::transaction(function () use ($route, $actor) {
            $route = Route::whereKey($route->getKey())->lockForUpdate()->firstOrFail();

            if ($route->schedules()->where('is_active', true)->exists()) {
                throw new BusinessRuleException(
                    'This route still has active schedules. Deactivate them before removing the route.',
                );
            }

            $riders = $route->students()->count();

            if ($riders > 0) {
                throw new BusinessRuleException(
                    "This route still has {$riders} student(s) assigned to it.",
                    ['students_affected' => $riders],
                );
            }

            $route->status = RouteStatus::INACTIVE;
            $route->save();
            $route->delete();

            $this->audit->deleted($route, $actor);
        });
    }

    /**
     * Fetch a stop, asserting it really belongs to this route.
     *
     * Without this check, a caller could edit any stop in the system by
     * pairing its id with a route they are allowed to touch.
     */
    public function findStopOnRoute(Route $route, string $stopId): RouteStop
    {
        $stop = RouteStop::where('id', $stopId)
            ->where('route_id', $route->getKey())
            ->first();

        if (! $stop) {
            throw new ResourceNotFoundException('Stop not found on this route.');
        }

        return $stop;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * Move every stop at or after `$from` by `$by` positions.
     *
     * Ordering matters: shifting down (+1) must start from the highest
     * sequence, or the update would collide with a row it has not moved yet
     * and trip the unique index.
     */
    private function shiftStopsFrom(Route $route, int $from, int $by): void
    {
        if ($by === 0) {
            return;
        }

        $query = RouteStop::where('route_id', $route->getKey())
            ->where('sequence_number', '>=', $from)
            ->orderBy('sequence_number', $by > 0 ? 'desc' : 'asc');

        foreach ($query->get() as $stop) {
            $stop->forceFill(['sequence_number' => $stop->sequence_number + $by])->save();
        }
    }

    /**
     * Move a stop to a new position, resequencing the rest around it.
     */
    private function moveStop(Route $route, RouteStop $stop, int $target): void
    {
        $count = $route->stops()->count();
        $target = min(max($target, 1), $count);
        $current = $stop->sequence_number;

        if ($target === $current) {
            return;
        }

        // Park the stop outside the valid range so the rows it passes can be
        // renumbered without colliding with it.
        $stop->forceFill(['sequence_number' => -1])->save();

        if ($target < $current) {
            // Moving earlier: everything between target and current-1 shifts down.
            $between = RouteStop::where('route_id', $route->getKey())
                ->whereBetween('sequence_number', [$target, $current - 1])
                ->orderBy('sequence_number', 'desc')
                ->get();

            foreach ($between as $other) {
                $other->forceFill(['sequence_number' => $other->sequence_number + 1])->save();
            }
        } else {
            // Moving later: everything between current+1 and target shifts up.
            $between = RouteStop::where('route_id', $route->getKey())
                ->whereBetween('sequence_number', [$current + 1, $target])
                ->orderBy('sequence_number', 'asc')
                ->get();

            foreach ($between as $other) {
                $other->forceFill(['sequence_number' => $other->sequence_number - 1])->save();
            }
        }

        $stop->forceFill(['sequence_number' => $target])->save();
    }
}
