<?php

namespace App\Events\Trips;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Events\DomainEvent;
use App\Models\TripConsolidation;
use App\Notifications\NotificationIntent;

/**
 * BR-363 — the affected passengers are told a merge is happening.
 *
 * Despite the name, this fires **before** the merge takes effect, when
 * `pending` is true. That is the rule: the notification is a precondition of
 * execution, not a record of it. Somebody standing at a stop needs to know
 * which bus to get on *while they can still get on it*.
 */
class ConsolidationExecuted extends DomainEvent implements NotifiesUsers
{
    public function __construct(
        public readonly TripConsolidation $consolidation,
        public readonly bool $pending = false,
    ) {}

    public function eventKey(): string
    {
        return 'consolidation.passengers_notified';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $source = $this->consolidation->sourceTrip;
        $target = $this->consolidation->targetTrip;

        if ($source?->route === null || $target === null) {
            return [];
        }

        $riders = $source->route->students()->with('user')->get()
            ->map(fn ($student) => $student->user)
            ->filter()
            ->all();

        if ($riders === []) {
            return [];
        }

        $registration = $target->bus?->registration_number;
        $targetName = $target->route?->route_name ?? 'another service';

        // A registration number is the only thing that makes a different bus
        // recognisable at the kerb.
        $which = $registration !== null
            ? "Look for {$registration}."
            : 'Check the live map for the bus now covering your stop.';

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::TRIP,
                recipients: $riders,
                title: 'Your bus is being combined with another',
                body: "Your service is being merged into {$targetName}. {$which} "
                    .'Your stop is still served — do not make other arrangements.',
                // Critical: this changes which vehicle somebody must board, and
                // arriving after they have already gone home is a failure.
                priority: NotificationPriority::CRITICAL,
                data: [
                    'consolidation_id' => (string) $this->consolidation->getKey(),
                    'source_trip_id' => (string) $source->getKey(),
                    'target_trip_id' => (string) $target->getKey(),
                    'target_route_name' => $targetName,
                    'target_bus_registration' => $registration,
                    'pending' => $this->pending,
                ],
                subject: $this->consolidation,
            ),
        ];
    }
}
