<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

/**
 * Who may read and write service announcements.
 *
 * Reading is wide by design — an announcement exists to be read. What is
 * narrow is publication: a message that reaches every student and driver on
 * the system is an operations act.
 */
class AnnouncementPolicy
{
    public function viewAny(User $actor): bool
    {
        // Everyone sees their own audience's board; the controller scopes it.
        return true;
    }

    public function view(User $actor, Announcement $announcement): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        // A rider must not be able to read a draft, a withdrawn notice, or one
        // addressed to a different audience by guessing its id.
        return $announcement->isVisibleTo($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Announcement $announcement): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Publishing and withdrawing are the same authority.
     */
    public function publish(User $actor, Announcement $announcement): bool
    {
        return $actor->isAdmin();
    }
}
