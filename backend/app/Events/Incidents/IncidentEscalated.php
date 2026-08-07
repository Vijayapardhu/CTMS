<?php

namespace App\Events\Incidents;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Notifications\NotificationIntent;

/**
 * BR-356 — nobody acknowledged an incident within its class's tolerance.
 *
 * The escalation exists because an unacknowledged life-safety incident is
 * indistinguishable, from the outside, from one nobody ever saw. Two minutes
 * of silence on an SOS is the whole failure.
 */
class IncidentEscalated extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly VehicleIncident $incident) {}

    public function eventKey(): string
    {
        return 'incident.escalated';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $recipients = User::query()->role(UserRole::ADMIN)->active()->get()->all();

        if ($recipients === []) {
            return [];
        }

        $waited = (int) $this->incident->reported_at->diffInMinutes(now());

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::INCIDENT,
                recipients: $recipients,
                title: 'ESCALATED: '.$this->incident->incident_type->label().' unacknowledged',
                body: "Reported {$waited} minute(s) ago and nobody has acknowledged it. {$this->incident->description}",
                priority: NotificationPriority::CRITICAL,
                data: [
                    'incident_id' => (string) $this->incident->getKey(),
                    'class' => $this->incident->incident_class->value,
                    'minutes_unacknowledged' => $waited,
                ],
                subject: $this->incident,
                // One escalation per incident, not one per scan.
                dedupKey: 'incident.escalated:'.$this->incident->getKey(),
            ),
        ];
    }
}
