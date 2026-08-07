<?php

namespace App\Events\Network;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Route;
use App\Notifications\NotificationIntent;

/**
 * BR-213, N-27 — a route that students are assigned to has changed.
 *
 * Changing a child's route or stop without telling them is a safety failure,
 * not an administrative detail.
 */
class RouteChanged extends DomainEvent implements NotifiesUsers
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $changedFields = [],
        public readonly ?string $summary = null,
    ) {}

    public function eventKey(): string
    {
        return 'route.changed';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        // The domain knows who cares: the students riding this route.
        $riders = $this->route->students()->with('user')->get()
            ->map(fn ($student) => $student->user)
            ->filter()
            ->all();

        if ($riders === []) {
            return [];
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRANSPORT,
                recipients: $riders,
                title: "Your route has changed: {$this->route->route_name}",
                body: $this->summary ?? 'Details of your bus route have been updated. Check your schedule for the new times and stops.',
                data: [
                    'route_id' => (string) $this->route->getKey(),
                    'route_name' => $this->route->route_name,
                    'changed_fields' => $this->changedFields,
                ],
                subject: $this->route,
                // A route may legitimately change more than once; each change
                // is its own message.
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->route->getKey(),
                    now()->timestamp,
                ]),
            ),
        ];
    }
}
