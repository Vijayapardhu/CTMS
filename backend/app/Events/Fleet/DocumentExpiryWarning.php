<?php

namespace App\Events\Fleet;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\BusDocument;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * N-22 and N-23 — a statutory vehicle document is lapsing or has lapsed.
 *
 * Expiry is critical because it is a blocking condition (BR-055): the bus
 * stops being usable at midnight whether or not anybody noticed.
 */
class DocumentExpiryWarning extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly BusDocument $document,
        public readonly int $daysRemaining,
    ) {}

    public function eventKey(): string
    {
        return $this->hasExpired() ? 'fleet.document.expired' : 'fleet.document.expiring';
    }

    public function hasExpired(): bool
    {
        return $this->daysRemaining < 0;
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $bus = $this->document->bus;

        if ($bus === null) {
            return [];
        }

        $label = $this->document->document_type->label();
        $registration = $bus->registration_number;

        $administrators = User::query()->role(UserRole::ADMIN)->active()->get()->all();

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::FLEET,
                recipients: $administrators,
                title: $this->hasExpired()
                    ? "{$label} has expired: {$registration}"
                    : "{$label} expires in {$this->daysRemaining} days: {$registration}",
                body: $this->hasExpired()
                    ? "This bus cannot be assigned or scheduled until the {$label} is renewed."
                    : "Renew the {$label} for {$registration} before it lapses, or the bus comes off the road.",
                priority: $this->hasExpired()
                    ? NotificationPriority::CRITICAL
                    : NotificationPriority::STANDARD,
                data: [
                    'bus_id' => (string) $bus->getKey(),
                    'registration_number' => $registration,
                    'document_id' => (string) $this->document->getKey(),
                    'document_type' => $this->document->document_type->value,
                    'expires_on' => $this->document->expires_on->toDateString(),
                    'days_remaining' => $this->daysRemaining,
                ],
                subject: $this->document,
                // One warning per document per threshold, not one per scan.
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->document->getKey(),
                    max($this->daysRemaining, -1),
                ]),
            ),
        ];
    }
}
