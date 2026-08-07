<?php

namespace App\Events\Communication;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * A service announcement has been published (blueprint §Communication).
 *
 * The audience is resolved from roles at publication time, not stored, so a
 * student who joins tomorrow does not retroactively receive yesterday's notice
 * — they see it on the board instead, which is what the board is for.
 */
class AnnouncementPublished extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly Announcement $announcement) {}

    public function eventKey(): string
    {
        return 'announcement.published';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $roles = array_map(
            fn ($role) => $role->value,
            $this->announcement->target_audience->roles(),
        );

        $recipients = User::query()
            ->human()
            ->whereIn('role', $roles)
            ->where('is_active', true)
            ->get()
            ->all();

        if ($recipients === []) {
            return [];
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::ANNOUNCEMENT,
                recipients: $recipients,
                title: $this->announcement->title,
                body: $this->announcement->content,
                // Never critical, whatever the announcement's own priority
                // says. Critical bypasses quiet hours and mute (BR-402), and
                // that exemption belongs to a child in danger, not to the
                // notice board. Something that genuinely cannot wait is an
                // incident, and there is a route for that.
                priority: $this->announcement->priority->notificationPriority(),
                data: [
                    'announcement_id' => (string) $this->announcement->getKey(),
                    'priority' => $this->announcement->priority->value,
                    'audience' => $this->announcement->target_audience->value,
                    'expires_at' => $this->announcement->expires_at?->toIso8601String(),
                ],
                subject: $this->announcement,
            ),
        ];
    }
}
