<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;

/**
 * Record-level authorization for trips.
 *
 * The rule that matters: a driver reaches their own trips and nobody else's,
 * and a student reaches only trips on the route they are assigned to. A valid
 * token plus another trip's identifier must return nothing.
 */
class TripPolicy
{
    public function viewAny(User $actor): bool
    {
        // Staff browse the whole schedule; riders and drivers use their own
        // filtered views, which the controller scopes for them.
        return true;
    }

    public function view(User $actor, Trip $trip): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if ($actor->isDriver()) {
            return $actor->driver !== null
                && (string) $actor->driver->getKey() === (string) $trip->driver_id;
        }

        if ($actor->isStudent()) {
            // A student sees trips on the route they actually ride.
            return $actor->student !== null
                && $actor->student->route_id !== null
                && (string) $actor->student->route_id === (string) $trip->route_id;
        }

        return false;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Starting and ending are the assigned driver's job; operations can act
     * on their behalf when a driver's device has failed.
     */
    public function operate(User $actor, Trip $trip): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->isDriver()
            && $actor->driver !== null
            && (string) $actor->driver->getKey() === (string) $trip->driver_id;
    }

    /**
     * Cancelling affects every rider on the route; it is an operations call.
     */
    public function cancel(User $actor, Trip $trip): bool
    {
        return $actor->isAdmin();
    }

    public function reassign(User $actor, Trip $trip): bool
    {
        return $actor->isAdmin();
    }

    /**
     * BR-258 — correcting a closed trip's record.
     *
     * Never the driver, even for their own trip. The attendance record is the
     * evidence of what they did; letting them rewrite it afterwards would make
     * it worthless for the one purpose it exists to serve.
     */
    public function correct(User $actor, Trip $trip): bool
    {
        return $actor->isAdmin();
    }

    /**
     * BR-266 — reviewing an attendance disagreement.
     */
    public function reviewAttendance(User $actor): bool
    {
        return $actor->isAdmin();
    }
}
