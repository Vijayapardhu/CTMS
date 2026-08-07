<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "Tell these people this, about this."
 *
 * The integration contract between a domain module and the notification
 * platform. A module states *who cares and why* — domain knowledge it owns —
 * and knows nothing about channels, preferences, retries or devices. The
 * platform receives intents and knows nothing about buses.
 */
final class NotificationIntent
{
    /**
     * @param  array<int, User>  $recipients
     * @param  array<string, mixed>  $data  Payload for client deep-linking.
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly NotificationCategory $category,
        public readonly NotificationPriority $priority,
        public readonly array $recipients,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?Model $subject = null,
        private readonly ?string $dedupKey = null,
    ) {}

    public static function make(
        string $eventKey,
        NotificationCategory $category,
        array $recipients,
        string $title,
        string $body,
        NotificationPriority $priority = NotificationPriority::STANDARD,
        array $data = [],
        ?Model $subject = null,
        ?string $dedupKey = null,
    ): self {
        return new self(
            eventKey: $eventKey,
            category: $category,
            priority: $priority,
            recipients: $recipients,
            title: $title,
            body: $body,
            data: $data,
            subject: $subject,
            dedupKey: $dedupKey,
        );
    }

    /**
     * The key that makes this notification unique per recipient (BR-405).
     *
     * Defaults to the event plus its subject, which is what stops a retried
     * job or a re-published event from telling somebody the same thing twice.
     * An event that legitimately recurs for one subject — "bus approaching",
     * once per trip — supplies its own key including the distinguishing part.
     */
    public function dedupKeyFor(User $recipient): string
    {
        $key = $this->dedupKey ?? implode(':', array_filter([
            $this->eventKey,
            $this->subject?->getMorphClass(),
            $this->subject?->getKey(),
        ]));

        return mb_substr($key, 0, 191);
    }

    /**
     * @return array<int, User>
     */
    public function recipients(): array
    {
        // A person is told once, however many ways the domain found them.
        $unique = [];

        foreach ($this->recipients as $recipient) {
            if ($recipient instanceof User) {
                $unique[(string) $recipient->getKey()] = $recipient;
            }
        }

        return array_values($unique);
    }

    public function hasRecipients(): bool
    {
        return $this->recipients() !== [];
    }
}
