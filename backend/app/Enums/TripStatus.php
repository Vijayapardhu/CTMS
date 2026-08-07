<?php

namespace App\Enums;

/**
 * Trip status enumeration.
 *
 * A trip moves forward only: SCHEDULED -> RUNNING -> COMPLETED. It may be
 * cancelled before it completes, but a terminal state is never reopened.
 */
enum TripStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

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
     * States this trip may legally move to next.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::SCHEDULED => [self::RUNNING, self::CANCELLED],
            self::RUNNING => [self::COMPLETED, self::CANCELLED],
            // Terminal states.
            self::COMPLETED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the trip has reached a terminal state and can no longer change.
     */
    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::CANCELLED;
    }
}
