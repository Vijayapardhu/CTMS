<?php

namespace App\Events\Maintenance;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\Bus;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * BR-358 — a bus is roadworthy again.
 *
 * Published only when the *last* grounding ticket closes, so it means what it
 * says. A vehicle with a repaired gearbox and outstanding failed brakes does
 * not produce this event.
 */
class VehicleReturnedToService extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly Bus $bus) {}

    public function eventKey(): string
    {
        return 'maintenance.vehicle.returned_to_service';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $operations = User::query()->role(UserRole::ADMIN)->active()->get()->all();

        if ($operations === []) {
            return [];
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::FLEET,
                recipients: $operations,
                title: "{$this->bus->registration_number} is back in service",
                body: 'All maintenance holding this vehicle off the road has been signed off. '
                    .'It can be assigned again.',
                priority: NotificationPriority::STANDARD,
                data: [
                    'bus_id' => (string) $this->bus->getKey(),
                    'registration_number' => $this->bus->registration_number,
                ],
                subject: $this->bus,
            ),
        ];
    }
}
