<?php

namespace App\Events\Tracking;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Models\Trip;
use App\Notifications\NotificationIntent;

/**
 * N-06 — the trip is running late.
 *
 * Delay is an event, not a field somebody reads off a dashboard, so reports,
 * live views and notifications all react to the same signal.
 */
class TripDelayed extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Trip $trip,
        public readonly int $delayMinutes,
    ) {}

    public function eventKey(): string
    {
        return 'trip.delayed';
    }

    /**
     * Delay bands, so a bus that stays late does not notify on every ping.
     * Crossing from "10 minutes late" to "30 minutes late" is news; drifting
     * from 11 to 12 is not.
     */
    private function band(): int
    {
        return match (true) {
            $this->delayMinutes >= 60 => 60,
            $this->delayMinutes >= 30 => 30,
            $this->delayMinutes >= 20 => 20,
            default => 10,
        };
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        // Only the people still waiting — anyone already aboard can see they
        // are late without being told.
        $waiting = Student::with('user')
            ->where('route_id', $this->trip->route_id)
            ->whereDoesntHave('passengerLogs', function ($query) {
                $query->where('trip_id', $this->trip->getKey())
                    ->where('action', 'BOARDED');
            })
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
                category: NotificationCategory::TRIP,
                recipients: $waiting,
                title: 'Your bus is running late',
                body: "About {$this->delayMinutes} minutes behind schedule. Live arrival times are updating in the app.",
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'delay_minutes' => $this->delayMinutes,
                ],
                subject: $this->trip,
                // One message per band per trip.
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->trip->getKey(),
                    $this->band(),
                ]),
            ),
        ];
    }
}
