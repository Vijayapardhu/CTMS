<?php

namespace App\Enums;

/**
 * What kind of guarantee an incident needs.
 *
 * Incidents are not one thing. A flat tyre and a medical emergency travel
 * through the same endpoint and need completely different reliability: one
 * must never fail silently and reaches a human in seconds; the other opens a
 * ticket and waits for the workshop. Classing them makes those differences
 * explicit rather than leaving them to the severity field.
 */
enum IncidentClass: string
{
    /**
     * Class A — someone may be hurt. Never fails silently, always escalates,
     * always reaches a human. Delivered on every channel at once.
     */
    case LIFE_SAFETY = 'LIFE_SAFETY';

    /**
     * Class B — the vehicle cannot continue, or should not. Opens a
     * maintenance ticket, notifies operations, evaluates a replacement.
     */
    case OPERATIONAL = 'OPERATIONAL';

    /**
     * Class C — the service is degraded but running. Updates estimates,
     * informs affected passengers, feeds analytics.
     */
    case SERVICE = 'SERVICE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::LIFE_SAFETY => 'Life safety',
            self::OPERATIONAL => 'Operational',
            self::SERVICE => 'Service',
        };
    }

    /**
     * Whether reporting this takes the bus off the road immediately.
     */
    public function takesBusOutOfService(): bool
    {
        return $this !== self::SERVICE;
    }

    /**
     * Whether a replacement vehicle is evaluated automatically (BR-352).
     */
    public function triggersReplacementSearch(): bool
    {
        return $this !== self::SERVICE;
    }

    /**
     * Minutes before an unacknowledged incident escalates. Null means never.
     */
    public function escalationMinutes(): ?int
    {
        return match ($this) {
            self::LIFE_SAFETY => 2,
            self::OPERATIONAL => 15,
            self::SERVICE => null,
        };
    }
}
