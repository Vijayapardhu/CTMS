<?php

namespace App\Events\Tracking;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Notifications\NotificationIntent;

/**
 * N-08 — the bus passed a stop without serving it.
 *
 * Critical, and immediate. Someone is standing at that stop expecting a bus
 * that is not going to stop; telling them when the trip ends is telling them
 * far too late.
 */
class StopSkipped extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Trip $trip,
        public readonly TripStopProgress $progress,
        public readonly string $reason,
    ) {}

    public function eventKey(): string
    {
        return 'trip.stop.skipped';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $stop = $this->progress->stop;

        if ($stop === null) {
            return [];
        }

        $waiting = Student::with('user')
            ->where('route_id', $this->trip->route_id)
            ->where('pickup_stop_id', $stop->getKey())
            ->get()
            ->map(fn (Student $student) => $student->user)
            ->filter()
            ->all();

        if ($waiting === []) {
            return [];
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::INCIDENT,
                recipients: $waiting,
                title: "Your bus did not stop at {$stop->stop_name}",
                body: "Reason: {$this->reason}. Contact the transport office — they are arranging onward travel.",
                priority: NotificationPriority::CRITICAL,
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'stop_id' => (string) $stop->getKey(),
                    'stop_name' => $stop->stop_name,
                    'reason' => $this->reason,
                ],
                subject: $this->trip,
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->trip->getKey(),
                    $stop->getKey(),
                ]),
            ),
        ];
    }
}
