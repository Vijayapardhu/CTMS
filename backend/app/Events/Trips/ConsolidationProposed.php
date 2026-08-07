<?php

namespace App\Events\Trips;

use App\Contracts\NotifiesUsers;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Enums\UserRole;
use App\Events\DomainEvent;
use App\Models\TripConsolidation;
use App\Models\User;
use App\Notifications\NotificationIntent;

/**
 * A merge has been proposed and is waiting on a manager (BR-361).
 *
 * Passengers are deliberately **not** told at this point. A proposal that may
 * never be approved is not news anyone can act on, and warning people their
 * bus might be cancelled — when it probably will not be — is its own harm.
 * They are told at BR-363 time, once the decision is real.
 */
class ConsolidationProposed extends DomainEvent implements NotifiesUsers
{
    public function __construct(public readonly TripConsolidation $consolidation) {}

    public function eventKey(): string
    {
        return 'consolidation.proposed';
    }

    /**
     * @return array<int, NotificationIntent>
     */
    public function notificationIntents(): array
    {
        $managers = User::query()
            ->where('role', UserRole::ADMIN->value)
            ->where('is_active', true)
            ->get()
            ->all();

        if ($managers === []) {
            return [];
        }

        $source = $this->consolidation->sourceTrip;
        $target = $this->consolidation->targetTrip;

        $sourceName = $source?->route?->route_name ?? 'a trip';
        $targetName = $target?->route?->route_name ?? 'another trip';

        return [
            NotificationIntent::make(
                eventKey: $this->eventKey(),
                category: NotificationCategory::FLEET,
                recipients: $managers,
                title: 'Consolidation proposed',
                body: "{$sourceName} could be merged into {$targetName}: "
                    ."{$this->consolidation->combinedPassengers()} passengers combined against "
                    ."{$this->consolidation->target_capacity} seats. "
                    .'The proposal expires if nobody decides.',
                priority: NotificationPriority::STANDARD,
                data: [
                    'consolidation_id' => (string) $this->consolidation->getKey(),
                    'source_trip_id' => (string) $this->consolidation->source_trip_id,
                    'target_trip_id' => (string) $this->consolidation->target_trip_id,
                    'combined_passengers' => $this->consolidation->combinedPassengers(),
                    'target_capacity' => $this->consolidation->target_capacity,
                    'estimated_savings' => (float) $this->consolidation->estimated_savings,
                    'expires_at' => $this->consolidation->expires_at?->toIso8601String(),
                ],
                subject: $this->consolidation,
            ),
        ];
    }
}
