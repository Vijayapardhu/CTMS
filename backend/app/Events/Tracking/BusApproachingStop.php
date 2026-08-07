<?php

namespace App\Events\Tracking;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Notifications\NotificationIntent;

/**
 * N-02 — the bus has entered a stop's geofence.
 *
 * Fired on entry rather than on confirmed arrival, deliberately: the point of
 * this message is to give someone time to reach the kerb, and confirmation
 * costs seconds they need.
 */
class BusApproachingStop extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Trip $trip,
        public readonly TripStopProgress $progress,
    ) {}

    public function eventKey(): string
    {
        return 'trip.stop.approaching';
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

        // Only the people waiting at *this* stop — not the whole route.
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
                category: NotificationCategory::ARRIVAL,
                recipients: $waiting,
                title: 'Your bus is arriving',
                body: "The bus is arriving at {$stop->stop_name}.",
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'stop_id' => (string) $stop->getKey(),
                    'stop_name' => $stop->stop_name,
                ],
                subject: $this->trip,
                // BR-308 — once per stop per trip. Repeated alerts train
                // people to ignore them.
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->trip->getKey(),
                    $stop->getKey(),
                ]),
            ),
        ];
    }
}
