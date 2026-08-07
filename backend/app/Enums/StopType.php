<?php

namespace App\Enums;

/**
 * What a stop is used for. Must match the `route_stops.stop_type` column.
 */
enum StopType: string
{
    case PICKUP = 'PICKUP';
    case DROPOFF = 'DROPOFF';
    case BOTH = 'BOTH';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function allowsPickup(): bool
    {
        return $this === self::PICKUP || $this === self::BOTH;
    }

    public function allowsDropoff(): bool
    {
        return $this === self::DROPOFF || $this === self::BOTH;
    }
}
