<?php

namespace App\Policies;

use App\Models\Bus;
use App\Models\User;

/**
 * Fleet visibility is wide, fleet mutation is narrow.
 *
 * Drivers and students need to see buses to know what they are riding;
 * only administrators may change the fleet.
 */
class BusPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Any authenticated user may browse the fleet.
    }

    public function view(User $actor, Bus $bus): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Bus $bus): bool
    {
        return $actor->isAdmin();
    }

    public function changeStatus(User $actor, Bus $bus): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Statutory documents are a compliance record, maintained by the transport
     * office. Drivers may read them on their own bus but never change them.
     */
    public function manageDocuments(User $actor, Bus $bus): bool
    {
        return $actor->isAdmin();
    }

    public function delete(User $actor, Bus $bus): bool
    {
        return $actor->isAdmin();
    }
}
