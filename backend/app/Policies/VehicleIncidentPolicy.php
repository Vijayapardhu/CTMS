<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleIncident;

/**
 * Who may report and triage incidents.
 *
 * Reporting is deliberately wide: anybody operating a vehicle can raise one,
 * and a system that makes people wonder whether they are allowed to report an
 * emergency has already failed.
 */
class VehicleIncidentPolicy
{
    public function viewAny(User $actor): bool
    {
        // Drivers see their own reports; the controller scopes the query.
        return $actor->isAdmin() || $actor->isDriver();
    }

    public function view(User $actor, VehicleIncident $incident): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return (string) $incident->reported_by_id === (string) $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $actor->isDriver() || $actor->isAdmin();
    }

    /**
     * Acknowledging, resolving and closing are operations decisions.
     */
    public function triage(User $actor, VehicleIncident $incident): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Only the person who raised a false alarm may withdraw it — and
     * operations, who may already have acted on it.
     */
    public function cancel(User $actor, VehicleIncident $incident): bool
    {
        return $actor->isAdmin()
            || (string) $incident->reported_by_id === (string) $actor->getKey();
    }
}
