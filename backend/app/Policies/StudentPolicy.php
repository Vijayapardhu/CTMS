<?php

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Student;
use App\Models\User;

/**
 * Student records carry hostel addresses and emergency contacts. They are not
 * browsable, and a student sees only their own.
 */
class StudentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, Student $student): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->isStudent() && $student->user_id === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * A student may edit their own contact details; the controller narrows
     * which fields that actually covers.
     */
    public function update(User $actor, Student $student): bool
    {
        if ($actor->isStudent() && $student->user_id === $actor->getKey()) {
            return true;
        }

        // Everything else on a student's record — the paid entitlement, the
        // registration number — is an operations decision, exactly as seating
        // them on a route and changing their status already are.
        return $actor->hasAccessLevel(AccessLevel::OPERATIONS);
    }

    /**
     * Seating a student on a route is an operations decision.
     */
    public function assignTransport(User $actor, Student $student): bool
    {
        return $actor->isAdmin();
    }

    public function changeStatus(User $actor, Student $student): bool
    {
        return $actor->isAdmin();
    }

    public function delete(User $actor, Student $student): bool
    {
        return $actor->isAdmin();
    }
}
