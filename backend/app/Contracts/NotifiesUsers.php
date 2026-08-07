<?php

namespace App\Contracts;

use App\Notifications\NotificationIntent;

/**
 * A domain event that should tell somebody something.
 *
 * Implemented by events in the modules that own the domain knowledge. The
 * notification platform listens for this interface and never needs to know
 * what a bus, a route or an inspection is.
 */
interface NotifiesUsers
{
    /**
     * The notifications this event produces. An event with nothing to say for
     * a particular occurrence returns an empty array.
     *
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array;
}
