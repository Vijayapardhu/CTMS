<?php

namespace App\Enums;

/**
 * Driver status enumeration.
 *
 * Tracks the current duty state of a driver. Must match the `drivers.status`
 * column definition exactly.
 */
enum DriverStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case ON_TRIP = 'ON_TRIP';
    case LEAVE = 'LEAVE';
    case OFF_DUTY = 'OFF_DUTY';

    /**
     * All values as plain strings, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * States this driver may legally move to next.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AVAILABLE => [self::ON_TRIP, self::LEAVE, self::OFF_DUTY],
            // A driver mid-trip must finish before going on leave.
            self::ON_TRIP => [self::AVAILABLE, self::OFF_DUTY],
            self::LEAVE => [self::AVAILABLE, self::OFF_DUTY],
            self::OFF_DUTY => [self::AVAILABLE, self::LEAVE],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether a driver in this state may be assigned to a new trip.
     */
    public function isAssignable(): bool
    {
        return $this === self::AVAILABLE;
    }
}
