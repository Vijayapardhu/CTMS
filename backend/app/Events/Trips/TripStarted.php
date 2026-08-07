<?php

namespace App\Events\Trips;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Trip;
use App\Notifications\NotificationIntent;

/**
 * N-01 — a trip has departed.
 */
class TripStarted extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly Trip $trip) {}

    public function eventKey(): string
    {
        return 'trip.started';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $route = $this->trip->route;

        if ($route === null) {
            return [];
        }

        // The domain knows who cares: the students riding this route.
        $riders = $route->students()->with('user')->get()
            ->map(fn ($student) => $student->user)
            ->filter()
            ->all();

        if ($riders === []) {
            return [];
        }

        $delay = $this->trip->delayMinutes();

        $body = $delay > 5
            ? "Your bus has left, running about {$delay} minutes late."
            : 'Your bus has left and is on its way.';

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRIP,
                recipients: $riders,
                title: "{$route->route_name} is on the way",
                body: $body,
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'route_id' => (string) $route->getKey(),
                    'route_name' => $route->route_name,
                    'delay_minutes' => $delay,
                ],
                subject: $this->trip,
            ),
        ];
    }
}
