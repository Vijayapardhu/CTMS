<?php

namespace App\Events\Trips;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * N-10 — a trip has finished.
 *
 * A normally-closed trip tells nobody: arrival confirmations belong to
 * attendance (4B), where they are per-passenger and actually informative. An
 * auto-closed trip tells operations, because it means a driver left a trip
 * running and the record needs review (BR-260, BR-261).
 */
class TripCompleted extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Trip $trip,
        public readonly bool $autoClosed = false,
    ) {}

    public function eventKey(): string
    {
        return $this->autoClosed ? 'trip.auto_closed' : 'trip.completed';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        if (! $this->autoClosed) {
            return [];
        }

        $route = $this->trip->route;
        $operations = User::query()->role(UserRole::ADMIN)->active()->get()->all();

        if ($operations === []) {
            return [];
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::SYSTEM,
                recipients: $operations,
                title: 'A trip was closed automatically',
                body: sprintf(
                    '%s on %s was still running past its arrival time and has been closed. The record needs review.',
                    $route?->route_name ?? 'A trip',
                    $this->trip->trip_date->toDateString(),
                ),
                priority: NotificationPriority::STANDARD,
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'route_id' => (string) $this->trip->route_id,
                    'trip_date' => $this->trip->trip_date->toDateString(),
                ],
                subject: $this->trip,
            ),
        ];
    }
}
