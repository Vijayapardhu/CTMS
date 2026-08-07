<?php

namespace App\Events\People;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Student;
use App\Notifications\NotificationIntent;

/**
 * N-25 — a student has been seated on a route.
 */
class StudentTransportAssigned extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Student $student,
        public readonly Route $route,
        public readonly RouteStop $pickupStop,
        public readonly bool $wasReassignment = false,
    ) {}

    public function eventKey(): string
    {
        return $this->wasReassignment ? 'student.transport.changed' : 'student.transport.assigned';
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

        $verb = $this->wasReassignment ? 'updated' : 'assigned';

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRANSPORT,
                recipients: [$user],
                title: 'Your transport has been '.$verb,
                body: "You are on {$this->route->route_name}, boarding at {$this->pickupStop->stop_name}.",
                data: [
                    'route_id' => (string) $this->route->getKey(),
                    'route_name' => $this->route->route_name,
                    'pickup_stop_id' => (string) $this->pickupStop->getKey(),
                    'pickup_stop_name' => $this->pickupStop->stop_name,
                ],
                subject: $this->student,
                // Re-seating the same student must produce a fresh message
                // rather than colliding with the previous assignment.
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->student->getKey(),
                    $this->route->getKey(),
                    $this->pickupStop->getKey(),
                ]),
            ),
        ];
    }
}
