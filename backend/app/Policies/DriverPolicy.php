<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;

/**
 * Driver records hold licence numbers and violation history — personal data.
 * They are not browsable by students, and a driver sees only their own.
 */
class DriverPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, Driver $driver): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        // A driver may read their own record, and nobody else's.
        return $actor->isDriver() && $driver->user_id === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Driver $driver): bool
    {
        return $actor->isAdmin();
    }

    public function changeStatus(User $actor, Driver $driver): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        // A driver may mark themselves available or off duty; the state
        // machine in the service decides whether the specific move is legal.
        return $actor->isDriver() && $driver->user_id === $actor->getKey();
    }

    public function assignBus(User $actor, Driver $driver): bool
    {
        // Assigning vehicles is an operations decision, never self-service.
        return $actor->isAdmin();
    }

    public function delete(User $actor, Driver $driver): bool
    {
        return $actor->isAdmin();
    }
}
