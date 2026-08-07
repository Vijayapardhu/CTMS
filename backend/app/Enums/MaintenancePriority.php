<?php

namespace App\Enums;

/**
 * How urgently a ticket has to be dealt with (FR-14).
 */
enum MaintenancePriority: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case URGENT = 'URGENT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Sort weight, highest first. Used so an urgent ticket is never on page
     * two of the workshop's queue.
     */
    public function weight(): int
    {
        return match ($this) {
            self::URGENT => 0,
            self::HIGH => 1,
            self::MEDIUM => 2,
            self::LOW => 3,
        };
    }

    /**
     * BR-358 — whether this ticket keeps the bus off the road until closed.
     *
     * An urgent fault means the vehicle is not roadworthy. A low-priority one
     * (a torn seat cover, a sticking window) does not, and grounding a bus for
     * it would strand a route over cosmetics.
     */
    public function groundsTheVehicle(): bool
    {
        return $this === self::URGENT || $this === self::HIGH;
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::URGENT => 'Urgent',
        };
    }
}
