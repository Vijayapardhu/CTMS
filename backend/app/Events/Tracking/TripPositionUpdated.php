<?php

namespace App\Events\Tracking;

use App\Events\DomainEvent;
use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * A new position is available for a running trip.
 *
 * Broadcast, not notified: this is a stream for anyone watching the map right
 * now. Turning every GPS ping into a notification would be unusable, which is
 * why this event deliberately does not implement NotifiesUsers.
 */
class TripPositionUpdated extends DomainEvent implements ShouldBroadcast
{
    public function __construct(
        public readonly Trip $trip,
        public readonly TripLocation $location,
    ) {}

    public function eventKey(): string
    {
        return 'trip.position.updated';
    }

    /**
     * The per-trip channel. Authorization lives in routes/channels.php and is
     * evaluated at subscribe and again on every reconnect (BR-304).
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('trips.'.$this->trip->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'position.updated';
    }

    /**
     * Only what a map needs. No passenger data goes onto a broadcast channel.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'trip_id' => (string) $this->trip->getKey(),
            'latitude' => (float) $this->location->latitude,
            'longitude' => (float) $this->location->longitude,
            'heading' => $this->location->heading,
            'speed_kmh' => $this->location->speed_kmh,
            'recorded_at' => $this->location->recorded_at->toIso8601String(),
            'occupied_seat_count' => $this->trip->occupied_seat_count,
        ];
    }
}
