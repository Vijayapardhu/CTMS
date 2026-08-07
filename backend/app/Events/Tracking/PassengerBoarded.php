<?php

namespace App\Events\Tracking;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Models\Trip;
use App\Notifications\NotificationIntent;

/**
 * N-04 — a named student has boarded.
 *
 * This is the single most-watched event in the product. When the parent app
 * exists, its guardians are added to the recipient list here and nowhere else;
 * until then it confirms to the student that their ride is recorded.
 */
class PassengerBoarded extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Trip $trip,
        public readonly Student $student,
        public readonly ?string $routeStopId = null,
    ) {}

    public function eventKey(): string
    {
        return 'trip.passenger.boarded';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $user = $this->student->user;

        if ($user === null) {
            return [];
        }

        $stopName = $this->routeStopId !== null
            ? $this->trip->route?->stops()->whereKey($this->routeStopId)->first()?->stop_name
            : null;

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::ATTENDANCE,
                recipients: [$user],
                title: 'Boarding confirmed',
                body: $stopName !== null
                    ? "You boarded at {$stopName} at ".now()->format('H:i').'.'
                    : 'Your boarding has been recorded.',
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'stop_id' => $this->routeStopId,
                    'boarded_at' => now()->toIso8601String(),
                ],
                subject: $this->trip,
                // One boarding per student per trip.
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->trip->getKey(),
                    $this->student->getKey(),
                ]),
            ),
        ];
    }
}
