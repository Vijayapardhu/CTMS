<?php

namespace App\Enums;

/**
 * The geofence state machine for one stop on one trip (BR-308).
 *
 * Arrival is a transition, not a reading. A bus passing within the radius on a
 * parallel road, or a single GPS point that drifts, must not fire "your bus is
 * here" — so a stop moves to ARRIVED only after enough consecutive readings
 * inside the fence to be credible.
 *
 *   PENDING ──enters fence──► APPROACHING ──confirmed──► ARRIVED
 *      │                           │                        │
 *      │                           └──drift, leaves────┐    │ leaves fence
 *      │                                               ▼    ▼
 *      └──────────────skipped─────────────► SKIPPED   PENDING  DEPARTED
 */
enum StopProgressState: string
{
    /** Not yet reached. */
    case PENDING = 'PENDING';

    /** Inside the geofence, but not yet confirmed as an arrival. */
    case APPROACHING = 'APPROACHING';

    /** Confirmed at the stop. Boarding may happen. */
    case ARRIVED = 'ARRIVED';

    /** Left the stop. Terminal for this stop on this trip. */
    case DEPARTED = 'DEPARTED';

    /** Passed without stopping. Terminal, and it notifies the people waiting. */
    case SKIPPED = 'SKIPPED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::APPROACHING, self::ARRIVED, self::SKIPPED],
            // Drift out of the fence before confirmation returns to PENDING.
            self::APPROACHING => [self::ARRIVED, self::PENDING, self::SKIPPED],
            self::ARRIVED => [self::DEPARTED],
            self::DEPARTED, self::SKIPPED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::DEPARTED || $this === self::SKIPPED;
    }

    /**
     * Whether passengers may board or alight at this point.
     */
    public function permitsBoarding(): bool
    {
        return $this === self::ARRIVED;
    }
}
