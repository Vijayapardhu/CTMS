<?php

namespace App\Events\Incidents;

use App\Contracts\NotifiesUsers;
use App\Enums\IncidentClass;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Notifications\NotificationIntent;

/**
 * N-11, N-12, N-13 — an incident has been reported.
 *
 * Who is told, and how urgently, is decided by the incident's class. The two
 * passenger audiences are deliberately split (BR-365): someone aboard a
 * stricken bus and someone still waiting at a stop are in different situations
 * and need different instructions.
 */
class IncidentReported extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly VehicleIncident $incident) {}

    public function eventKey(): string
    {
        return $this->incident->isLifeSafety()
            ? 'incident.sos.raised'
            : 'incident.reported';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $intents = [$this->operationsIntent()];

        // A service-class incident does not interrupt passengers; the ETA
        // update they can already see is the appropriate signal.
        if ($this->incident->incident_class === IncidentClass::SERVICE) {
            return array_values(array_filter($intents));
        }

        foreach ([$this->aboardIntent(), $this->waitingIntent()] as $intent) {
            if ($intent !== null) {
                $intents[] = $intent;
            }
        }

        return array_values(array_filter($intents));
    }

    /**
     * Operations always hear about it. For a life-safety incident this is
     * CRITICAL and fans out to every channel at once (BR-353).
     */
    private function operationsIntent(): ?NotificationIntent
    {
        $operations = User::query()->role(UserRole::ADMIN)->active()->get()->all();

        if ($operations === []) {
            return null;
        }

        $bus = $this->incident->bus;
        $isLifeSafety = $this->incident->isLifeSafety();

        return NotificationIntent::make(
            eventKey: $this->eventKey(),
            category: NotificationCategory::INCIDENT,
            recipients: $operations,
            title: $isLifeSafety
                ? '🚨 '.$this->incident->incident_type->label().' — '.($bus?->registration_number ?? 'unknown bus')
                : $this->incident->incident_type->label().' reported',
            body: sprintf(
                '%s%s %s',
                $this->incident->description,
                $this->incident->passengers_aboard > 0
                    ? " {$this->incident->passengers_aboard} passenger(s) aboard."
                    : '',
                $isLifeSafety ? 'Respond immediately.' : '',
            ),
            priority: $isLifeSafety
                ? NotificationPriority::CRITICAL
                : NotificationPriority::STANDARD,
            data: [
                'incident_id' => (string) $this->incident->getKey(),
                'class' => $this->incident->incident_class->value,
                'type' => $this->incident->incident_type->value,
                'severity' => $this->incident->severity->value,
                'trip_id' => (string) $this->incident->trip_id,
                'bus_id' => (string) $this->incident->bus_id,
                'latitude' => $this->incident->latitude,
                'longitude' => $this->incident->longitude,
                'passengers_aboard' => $this->incident->passengers_aboard,
            ],
            subject: $this->incident,
        );
    }

    /**
     * BR-365 — the people on the bus. They must not get off, and they need to
     * know help is coming.
     */
    private function aboardIntent(): ?NotificationIntent
    {
        $trip = $this->incident->trip;

        if ($trip === null) {
            return null;
        }

        $aboard = Student::with('user')
            ->whereHas('passengerLogs', function ($query) use ($trip) {
                $query->where('trip_id', $trip->getKey())->where('action', 'BOARDED');
            })
            ->get()
            ->map(fn (Student $student) => $student->user)
            ->filter()
            ->all();

        if ($aboard === []) {
            return null;
        }

        return NotificationIntent::make(
            eventKey: $this->eventKey().'.aboard',
            category: NotificationCategory::INCIDENT,
            recipients: $aboard,
            title: 'Your bus has stopped',
            body: 'Stay on the bus and follow your driver\'s instructions. The transport office has been alerted and help is on the way.',
            priority: NotificationPriority::CRITICAL,
            data: [
                'incident_id' => (string) $this->incident->getKey(),
                'trip_id' => (string) $trip->getKey(),
                'audience' => 'aboard',
            ],
            subject: $this->incident,
        );
    }

    /**
     * BR-365 — the people still at their stops. Different instruction
     * entirely: do not keep waiting.
     */
    private function waitingIntent(): ?NotificationIntent
    {
        $trip = $this->incident->trip;

        if ($trip === null) {
            return null;
        }

        $waiting = Student::with('user')
            ->where('route_id', $trip->route_id)
            ->whereDoesntHave('passengerLogs', function ($query) use ($trip) {
                $query->where('trip_id', $trip->getKey())->where('action', 'BOARDED');
            })
            ->get()
            ->map(fn (Student $student) => $student->user)
            ->filter()
            ->all();

        if ($waiting === []) {
            return null;
        }

        return NotificationIntent::make(
            eventKey: $this->eventKey().'.waiting',
            category: NotificationCategory::INCIDENT,
            recipients: $waiting,
            title: 'Your bus has been delayed by an incident',
            body: 'Your bus cannot continue on its route. The transport office is arranging a replacement — you will be told as soon as it is on the way.',
            priority: NotificationPriority::CRITICAL,
            data: [
                'incident_id' => (string) $this->incident->getKey(),
                'trip_id' => (string) $trip->getKey(),
                'audience' => 'waiting',
            ],
            subject: $this->incident,
        );
    }
}
