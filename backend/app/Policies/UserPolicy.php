<?php

namespace App\Policies;

use App\Models\User;

/**
 * Record-level authorization for user accounts.
 *
 * Role alone is never enough: a student holding a valid token must not be able
 * to read or edit another student simply by changing the id in the URL.
 */
class UserPolicy
{
    /**
     * Only administrators may enumerate accounts.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * A user may read their own account; administrators may read any.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->isAdmin();
    }

    /**
     * A user may edit their own profile; administrators may edit any.
     */
    public function update(User $actor, User $target): bool
    {
        if ($target->is_system) {
            return false;
        }

        return $actor->is($target) || $actor->isAdmin();
    }

    /**
     * Activation and deactivation are administrative acts.
     *
     * An administrator may not deactivate their own account — that is how a
     * deployment ends up with zero reachable administrators.
     */
    public function setActiveState(User $actor, User $target): bool
    {
        // Activating the scheduler's identity would turn an unusable audit
        // subject into a login nobody owns the password to.
        if ($target->is_system) {
            return false;
        }

        return $actor->isAdmin() && ! $actor->is($target);
    }

    // There is deliberately no `delete` ability, and no DELETE /users/{id}
    // route for one to guard.
    //
    // Accounts are deactivated, never destroyed. A deleted user orphans every
    // audit row and every attendance record they appear in, which is exactly
    // the history BR-505 and BR-507 exist to protect. Deactivation achieves
    // what deletion is usually reached for — the account stops working
    // immediately, on every request — without destroying the record of what
    // it did. An unused `delete` method sat here until the hardening pass;
    // removing it makes the absence deliberate rather than accidental.
}
