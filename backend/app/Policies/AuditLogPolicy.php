<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * BR-507 — audit records are append-only; no role may edit or delete them.
 *
 * Note what is absent from this class: there is no `update`, no `delete` and
 * no `create`. Their absence is the rule. Laravel denies any ability a policy
 * does not define, so adding one later is a deliberate act somebody has to
 * justify in review, rather than something that happens by resource-controller
 * scaffolding.
 */
class AuditLogPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, AuditLog $log): bool
    {
        return $actor->isAdmin();
    }

    /**
     * BR-502 — a bulk personal-data export needs elevated privilege and a
     * stated reason. The reason is enforced by the FormRequest; the privilege
     * is here.
     */
    public function export(User $actor): bool
    {
        return $actor->isAdmin();
    }
}
