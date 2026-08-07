<?php

namespace App\Events\People;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Notifications\NotificationIntent;

/**
 * N-26 — a student's transport has been withdrawn.
 *
 * A student who turns up at a stop expecting a bus that is no longer coming
 * for them is exactly the failure this message prevents.
 */
class StudentTransportCleared extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly Student $student,
        public readonly ?string $reason = null,
    ) {}

    public function eventKey(): string
    {
        return 'student.transport.cleared';
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

        $body = 'You are no longer assigned to a bus route. Contact the transport office to arrange transport.';

        if ($this->reason !== null) {
            $body = "{$this->reason} {$body}";
        }

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRANSPORT,
                recipients: [$user],
                title: 'Your transport assignment has been removed',
                body: $body,
                data: ['student_id' => (string) $this->student->getKey()],
                subject: $this->student,
                dedupKey: implode(':', [
                    $this->eventKey(),
                    $this->student->getKey(),
                    now()->timestamp,
                ]),
            ),
        ];
    }
}
