<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Schedule $schedule): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Schedule $schedule): bool
    {
        return $actor->isAdmin();
    }

    public function delete(User $actor, Schedule $schedule): bool
    {
        return $actor->isAdmin();
    }
}
