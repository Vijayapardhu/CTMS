<?php

namespace App\Enums;

/**
 * Bus status enumeration.
 *
 * Tracks the operational state of a bus. Transitions are constrained by
 * {@see self::canTransitionTo()} — a bus never jumps between arbitrary states.
 */
enum BusStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case RUNNING = 'RUNNING';
    case MAINTENANCE = 'MAINTENANCE';
    case BREAKDOWN = 'BREAKDOWN';
    case OFFLINE = 'OFFLINE';

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
     * States this bus may legally move to next.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // An idle bus can be dispatched, sent for service, or retired.
            self::AVAILABLE => [self::RUNNING, self::MAINTENANCE, self::BREAKDOWN, self::OFFLINE],
            // A bus on the road finishes its trip, breaks down, or is pulled for service.
            self::RUNNING => [self::AVAILABLE, self::BREAKDOWN, self::MAINTENANCE],
            // Service completes back to the yard, or the bus is retired.
            self::MAINTENANCE => [self::AVAILABLE, self::OFFLINE],
            // A broken bus must be serviced before it can carry passengers again.
            self::BREAKDOWN => [self::MAINTENANCE, self::OFFLINE],
            // A retired bus can be recommissioned only via the yard.
            self::OFFLINE => [self::AVAILABLE, self::MAINTENANCE],
        };
    }

    /**
     * Whether a move to the given state is permitted. Re-asserting the current
     * state is always allowed and is a no-op.
     */
    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether a bus in this state may be assigned to a new trip.
     */
    public function isOperational(): bool
    {
        return $this === self::AVAILABLE;
    }
}
