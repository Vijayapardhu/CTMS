<?php

namespace App\Events\Tracking;

use App\Events\DomainEvent;
use App\Models\Trip;
use App\Models\TripStopProgress;

/**
 * The bus is confirmed at a stop.
 *
 * Deliberately not a notification: N-02 already told the people waiting here
 * that the bus was arriving, seconds earlier. A second message on confirmed
 * arrival is noise, and noise is what trains people to ignore the alerts that
 * matter. This event exists for the live map and for downstream reporting.
 */
class BusArrivedAtStop extends DomainEvent
{
    public function __construct(
        public readonly Trip $trip,
        public readonly TripStopProgress $progress,
    ) {}

    public function eventKey(): string
    {
        return 'trip.stop.arrived';
    }
}
