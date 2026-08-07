<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for every domain event in the system.
 *
 * Events are the integration contract between modules. A module publishes what
 * happened; it does not know or care who listens. That is what lets the trip
 * module tell the world a trip started without containing a single line about
 * push tokens.
 */
abstract class DomainEvent
{
    use Dispatchable, SerializesModels;

    /**
     * Stable identifier for this event type, used as the notification's
     * `event_key` and in the delivery log. Never renamed once shipped —
     * it is what historical records are filtered by.
     */
    abstract public function eventKey(): string;
}
