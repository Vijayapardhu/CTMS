<?php

namespace App\Enums;

/**
 * How urgently a notification must reach its recipient (BR-402, BR-403).
 *
 * Set per event rather than per category: "trip started" and "trip cancelled"
 * are both TRIP, and only one of them is allowed to wake somebody at night.
 */
enum NotificationPriority: string
{
    /**
     * Safety-critical. Ignores quiet hours, mute and channel preference, and
     * escalates to an alternate channel on failure.
     */
    case CRITICAL = 'CRITICAL';

    /** Everything else. Honours the user's preferences in full. */
    case STANDARD = 'STANDARD';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this priority overrides quiet hours, mute and preference.
     */
    public function overridesPreferences(): bool
    {
        return $this === self::CRITICAL;
    }

    /**
     * Whether a failure on the primary channel escalates to another.
     */
    public function escalatesOnFailure(): bool
    {
        return $this === self::CRITICAL;
    }

    /**
     * Queue this priority is dispatched on.
     */
    public function queue(): string
    {
        return $this === self::CRITICAL ? 'critical' : 'notifications';
    }
}
