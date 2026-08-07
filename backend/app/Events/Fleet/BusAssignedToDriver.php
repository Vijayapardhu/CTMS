<?php

namespace App\Events\Fleet;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Bus;
use App\Models\Driver;
use App\Notifications\NotificationIntent;

/**
 * N-16 — a driver has been given a vehicle.
 */
class BusAssignedToDriver extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Driver $driver,
        public readonly Bus $bus,
    ) {}

    public function eventKey(): string
    {
        return 'driver.bus.assigned';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $user = $this->driver->user;

        if ($user === null) {
            return [];
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::FLEET,
                recipients: [$user],
                title: 'You have been assigned a bus',
                body: "{$this->bus->registration_number} ({$this->bus->vehicle_name}). Complete the pre-trip inspection before your first trip.",
                data: [
                    'bus_id' => (string) $this->bus->getKey(),
                    'registration_number' => $this->bus->registration_number,
                ],
                subject: $this->driver,
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->driver->getKey(),
                    $this->bus->getKey(),
                ]),
            ),
        ];
    }
}
