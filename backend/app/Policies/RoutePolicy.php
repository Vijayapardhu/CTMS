<?php

namespace App\Policies;

use App\Models\Route;
use App\Models\User;

/**
 * Routes are public knowledge to anyone inside the system; changing them is
 * an operations act.
 */
class RoutePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Route $route): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Route $route): bool
    {
        return $actor->isAdmin();
    }

    public function manageStops(User $actor, Route $route): bool
    {
        return $actor->isAdmin();
    }

    public function delete(User $actor, Route $route): bool
    {
        return $actor->isAdmin();
    }
}
