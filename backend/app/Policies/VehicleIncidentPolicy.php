<?php

namespace App\Policies;

use App\Enums\AccessLevel;
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
        // Any driver, from the roadside. A system that makes somebody wonder
        // whether they may report an emergency has already failed.
        if ($actor->isDriver()) {
            return true;
        }

        // From the office it is a supervisor's act, not an observer's.
        return $actor->hasAccessLevel(AccessLevel::SUPPORT);
    }

    /**
     * Adding to the running commentary on an incident.
     *
     * Its own ability rather than `view`, which is what the controller asked
     * for until G3-3: reading an incident and writing on its record are not
     * the same permission, and conflating them let read-only oversight
     * annotate an emergency.
     */
    public function addNote(User $actor, VehicleIncident $incident): bool
    {
        if ((string) $incident->reported_by_id === (string) $actor->getKey()) {
            return true;
        }

        return $actor->hasAccessLevel(AccessLevel::SUPPORT);
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
        // The person who raised a false alarm may withdraw it.
        if ((string) $incident->reported_by_id === (string) $actor->getKey()) {
            return true;
        }

        // Withdrawing somebody else's alert cancels something others may
        // already have acted on.
        return $actor->hasAccessLevel(AccessLevel::SUPPORT);
    }
}
