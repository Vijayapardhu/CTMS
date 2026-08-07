<?php

namespace App\Events\Maintenance;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * BR-350 — a job has been raised against a bus (FR-14).
 *
 * Goes to operations, not to passengers: a workshop ticket is not news to
 * somebody waiting at a stop, and the thing that *is* news to them — their bus
 * being taken off the road — is published separately by the incident that
 * caused it.
 */
class MaintenanceTicketOpened extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly MaintenanceTicket $ticket) {}

    public function eventKey(): string
    {
        return 'maintenance.ticket.opened';
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

        $bus = $this->ticket->bus;
        $registration = $bus?->registration_number ?? 'a bus';

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::FLEET,
                recipients: $operations,
                title: "{$this->ticket->priority->label()} maintenance: {$registration}",
                body: $this->ticket->issue_description,
                // An urgent fault means the vehicle is off the road now, and
                // somebody has to find a replacement before the next run.
                priority: $this->ticket->priority->groundsTheVehicle()
                    ? NotificationPriority::CRITICAL
                    : NotificationPriority::STANDARD,
                data: [
                    'ticket_id' => (string) $this->ticket->getKey(),
                    'bus_id' => (string) $this->ticket->bus_id,
                    'registration_number' => $registration,
                    'priority' => $this->ticket->priority->value,
                    'grounds_vehicle' => $this->ticket->priority->groundsTheVehicle(),
                ],
                subject: $this->ticket,
            ),
        ];
    }
}
