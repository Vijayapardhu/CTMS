<?php

namespace App\Enums;

/**
 * Route status. Must match the `routes.status` column definition.
 */
enum RouteStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case MAINTENANCE = 'MAINTENANCE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether students may be assigned to this route and trips run on it.
     */
    public function isServiceable(): bool
    {
        return $this === self::ACTIVE;
    }
}
