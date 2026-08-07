<?php

namespace App\Policies;

use App\Models\MaintenanceTicket;
use App\Models\User;

/**
 * BR-358 — a bus returns to service only when its ticket is closed by an
 * authorised role.
 *
 * Signing off maintenance is what puts a vehicle back under passengers. It is
 * never a driver's call, including on a fault they reported themselves: the
 * pressure to get moving is exactly what the rule exists to resist.
 */
class MaintenanceTicketPolicy
{
    public function viewAny(User $actor): bool
    {
        // Drivers see the jobs raised against buses they are assigned; the
        // controller scopes the query.
        return $actor->isAdmin() || $actor->isDriver();
    }

    public function view(User $actor, MaintenanceTicket $ticket): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if (! $actor->isDriver()) {
            return false;
        }

        // A driver may read a ticket on the bus they are assigned to, so they
        // know why it is off the road.
        return $actor->driver !== null
            && (string) $actor->driver->assigned_bus_id === (string) $ticket->bus_id;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function manage(User $actor, MaintenanceTicket $ticket): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Completing is called out separately from managing because it is the act
     * that returns a vehicle to the road.
     */
    public function complete(User $actor, MaintenanceTicket $ticket): bool
    {
        return $actor->isAdmin();
    }
}
