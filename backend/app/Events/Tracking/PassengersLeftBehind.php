<?php

namespace App\Events\Tracking;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\Student;
use App\Models\Trip;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * N-09 — students who could not board because the bus was full.
 *
 * Two audiences, two messages: the students need to know they have not been
 * forgotten, and operations need to dispatch something. Silence here is the
 * failure mode that destroys trust in the whole service.
 */
class PassengersLeftBehind extends DomainEvent implements NotifiesUsers
{
    /**
     * @param  array<int, Student>  $students
     */
    public function __construct(
        public readonly Trip $trip,
        public readonly array $students,
        public readonly ?string $routeStopId = null,
    ) {}

    public function eventKey(): string
    {
        return 'trip.passengers.left_behind';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $affected = array_values(array_filter(array_map(
            fn (Student $student) => $student->user,
            $this->students,
        )));

        if ($affected === []) {
            return [];
        }

        $stopName = $this->trip->route?->stops()
            ->whereKey($this->routeStopId)->first()?->stop_name;

        $intents = [];

        $intents[] = NotificationIntent::make(
            eventKey: $this->eventKey(),
            category: NotificationCategory::INCIDENT,
            recipients: $affected,
            title: 'Your bus was full',
            body: $stopName !== null
                ? "The bus reached {$stopName} at capacity and could not pick you up. The transport office is arranging onward travel — stay where you are."
                : 'The bus was full and could not pick you up. The transport office is arranging onward travel.',
            priority: NotificationPriority::CRITICAL,
            data: [
                'trip_id' => (string) $this->trip->getKey(),
                'stop_id' => $this->routeStopId,
            ],
            subject: $this->trip,
        );

        $operations = User::query()->role(UserRole::ADMIN)->active()->get()->all();

        if ($operations !== []) {
            $count = count($affected);

            $intents[] = NotificationIntent::make(
                eventKey: $this->eventKey().'.operations',
                category: NotificationCategory::INCIDENT,
                recipients: $operations,
                title: "{$count} student(s) left behind — bus at capacity",
                body: sprintf(
                    '%s reached capacity%s. They need onward transport now.',
                    $this->trip->route?->route_name ?? 'A trip',
                    $stopName !== null ? " at {$stopName}" : '',
                ),
                priority: NotificationPriority::CRITICAL,
                data: [
                    'trip_id' => (string) $this->trip->getKey(),
                    'stop_id' => $this->routeStopId,
                    'student_count' => $count,
                ],
                subject: $this->trip,
            );
        }

        return $intents;
    }
}
