<?php

namespace App\Events\Auth;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\DomainEvent;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * N-39 — a password was changed.
 *
 * Sent to the person who did it, deliberately. If they did not do it, this is
 * the message that tells them their account is compromised, which is why it is
 * critical and why ACCOUNT is a non-mutable category.
 */
class PasswordChanged extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly User $user) {}

    public function eventKey(): string
    {
        return 'account.password.changed';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::ACCOUNT,
                recipients: [$this->user],
                title: 'Your password was changed',
                body: 'If this was not you, contact the transport office immediately — your account may be compromised.',
                priority: NotificationPriority::CRITICAL,
                data: ['changed_at' => now()->toIso8601String()],
                subject: $this->user,
                dedupKey: implode(':', [$this->eventKey(), $this->user->getKey(), now()->timestamp]),
            ),
        ];
    }
}
