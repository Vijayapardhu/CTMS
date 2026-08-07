<?php

namespace App\Policies;

use App\Models\EvidenceFile;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Models\VehicleInspectionItem;

/**
 * BR-367 — evidence is served only through an authorising check.
 *
 * These are photographs taken inside buses carrying children. Holding the id
 * is not authority to see the file: the id appears in an incident response,
 * which more people can read than should be able to open the picture.
 */
class EvidenceFilePolicy
{
    public function create(User $actor): bool
    {
        // Drivers upload from the roadside; operations uploads on their behalf
        // when a paper report comes in. Riders never do.
        return $actor->isDriver() || $actor->isAdmin();
    }

    /**
     * Who may download the bytes.
     */
    public function view(User $actor, EvidenceFile $evidence): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if (! $actor->isDriver()) {
            return false;
        }

        // The person who took the photograph may always see it back.
        if ((string) $evidence->uploaded_by_id === (string) $actor->getKey()) {
            return true;
        }

        return $this->driverOwnsTheSubject($actor, $evidence);
    }

    /**
     * A driver may open evidence attached to their own report, even where
     * somebody else uploaded it.
     */
    private function driverOwnsTheSubject(User $actor, EvidenceFile $evidence): bool
    {
        $subject = $evidence->attachable;

        if ($subject instanceof VehicleIncident) {
            return (string) $subject->reported_by_id === (string) $actor->getKey();
        }

        if ($subject instanceof VehicleInspectionItem) {
            return $actor->driver !== null
                && (string) $subject->inspection?->driver_id === (string) $actor->driver->getKey();
        }

        return false;
    }
}
