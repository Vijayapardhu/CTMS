<?php

namespace App\Events\Trips;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\DomainEvent;
use App\Models\Trip;
use App\Notifications\NotificationIntent;

/**
 * N-07 — a trip has been cancelled.
 *
 * Critical without exception (BR-402). A student standing at a stop for a bus
 * that is not coming is the failure this message exists to prevent, and it is
 * not one that should wait for quiet hours to end.
 */
class TripCancelled extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Trip $trip,
        public readonly string $reason,
        public readonly bool $wasRunning = false,
    ) {}

    public function eventKey(): string
    {
        return 'trip.cancelled';
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

        $riders = $route->students()->with('user')->get()
            ->map(fn ($student) => $student->user)
            ->filter()
            ->all();

        if ($riders === []) {
            return [];
        }

        // Someone already aboard a cancelled trip is in a different situation
        // from someone still waiting, and needs to be told something different.
        $body = $this->wasRunning
            ? "Your bus has stopped its journey. Reason: {$this->reason}. Stay where you are — the transport office is arranging onward travel."
            : "Your bus is not running today. Reason: {$this->reason}. Please make alternative arrangements.";

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRIP,
                recipients: $riders,
                title: "{$route->route_name} has been cancelled",
                body: $body,
                priority: NotificationPriority::CRITICAL,
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'route_id' => (string) $route->getKey(),
                    'route_name' => $route->route_name,
                    'reason' => $this->reason,
                    'was_running' => $this->wasRunning,
                ],
                subject: $this->trip,
            ),
        ];
    }
}
