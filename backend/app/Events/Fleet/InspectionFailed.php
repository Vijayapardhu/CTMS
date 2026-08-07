<?php

namespace App\Events\Fleet;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\User;
use App\Models\VehicleInspection;
use App\Notifications\NotificationIntent;

/**
 * N-20 — a pre-trip inspection failed and a bus is off the road.
 *
 * Critical: operations need this while there is still time to substitute a
 * vehicle, which is a window of minutes.
 */
class InspectionFailed extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly VehicleInspection $inspection) {}

    public function eventKey(): string
    {
        return 'inspection.failed';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $bus = $this->inspection->bus;

        if ($bus === null) {
            return [];
        }

        $faults = $this->inspection->failedSafetyCriticalItems()
            ->map(fn ($item) => $item->item->label())
            ->implode(', ');

        $operations = User::query()
            ->role(UserRole::ADMIN)
            ->active()
            ->get()
            ->all();

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::INCIDENT,
                recipients: $operations,
                title: "Bus {$bus->registration_number} has failed inspection",
                body: "Taken out of service. Failed on: {$faults}. Scheduled trips need a replacement vehicle.",
                priority: NotificationPriority::CRITICAL,
                data: [
                    'bus_id' => (string) $bus->getKey(),
                    'registration_number' => $bus->registration_number,
                    'inspection_id' => (string) $this->inspection->getKey(),
                    'maintenance_ticket_id' => (string) $this->inspection->maintenance_ticket_id,
                ],
                subject: $this->inspection,
            ),
        ];
    }
}
