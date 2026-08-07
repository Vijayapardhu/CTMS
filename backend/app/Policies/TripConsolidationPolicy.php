<?php

namespace App\Policies;

use App\Models\TripConsolidation;
use App\Models\User;

/**
 * BR-361 — consolidation requires manager approval.
 *
 * Merging two services is a decision about people's journeys home, taken to
 * save fuel. It is not a dispatcher's call to make, and it is certainly not a
 * driver's.
 */
class TripConsolidationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, TripConsolidation $consolidation): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Approving, rejecting and executing are all the same authority.
     */
    public function decide(User $actor, TripConsolidation $consolidation): bool
    {
        return $actor->isAdmin();
    }
}
