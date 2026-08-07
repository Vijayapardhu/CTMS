<?php

namespace App\Events\Incidents;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\DomainEvent;
use App\Models\ReplacementAssignment;
use App\Models\Student;
use App\Notifications\NotificationIntent;

/**
 * N-14 — a replacement vehicle is on its way.
 *
 * The message people stranded by a breakdown are actually waiting for. It
 * names the bus so they know what to look for.
 */
class ReplacementDispatched extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly ReplacementAssignment $assignment) {}

    public function eventKey(): string
    {
        return 'replacement.dispatched';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $trip = $this->assignment->trip;

        if ($trip === null) {
            return [];
        }

        $affected = Student::with('user')
            ->where('route_id', $trip->route_id)
            ->get()
            ->map(fn (Student $student) => $student->user)
            ->filter()
            ->all();

        if ($affected === []) {
            return [];
        }

        $registration = $this->assignment->replacementBus?->registration_number;

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRIP,
                recipients: $affected,
                title: 'A replacement bus is on the way',
                body: $registration !== null
                    ? "Look out for {$registration}. Stay where you are — it is coming to you."
                    : 'A replacement bus has been dispatched. Stay where you are.',
                priority: NotificationPriority::CRITICAL,
                data: [
                    'trip_id' => (string) $trip->getKey(),
                    'replacement_assignment_id' => (string) $this->assignment->getKey(),
                    'replacement_bus_id' => (string) $this->assignment->replacement_bus_id,
                    'registration_number' => $registration,
                ],
                subject: $this->assignment,
            ),
        ];
    }
}
