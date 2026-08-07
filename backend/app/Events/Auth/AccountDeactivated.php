<?php

namespace App\Events\Auth;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\DomainEvent;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * N-38 — an account has been deactivated.
 *
 * Dispatched before the deactivation takes effect on the recipient's session,
 * because a student who simply stops being able to sign in with no explanation
 * generates a support call and a stranded rider.
 */
class AccountDeactivated extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly User $user) {}

    public function eventKey(): string
    {
        return 'account.deactivated';
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
                title: 'Your account has been deactivated',
                body: 'You will no longer be able to sign in or travel. Contact the transport office if you believe this is a mistake.',
                priority: NotificationPriority::CRITICAL,
                data: [],
                subject: $this->user,
                dedupKey: implode(':', [$this->eventKey(), $this->user->getKey(), now()->timestamp]),
            ),
        ];
    }
}
