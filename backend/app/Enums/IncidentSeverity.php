<?php

namespace App\Enums;

/**
 * Incident severity enumeration.
 *
 * Drives maintenance ticket priority and whether a replacement bus is
 * recommended automatically.
 */
enum IncidentSeverity: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

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
     * Whether an incident of this severity takes the bus off the road
     * immediately and triggers a replacement recommendation.
     */
    public function requiresImmediateReplacement(): bool
    {
        return $this === self::HIGH || $this === self::CRITICAL;
    }
}
