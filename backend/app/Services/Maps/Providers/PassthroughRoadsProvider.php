<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\RoadsProvider;
use App\Services\Maps\Support\LatLng;

/**
 * Road snapping with no provider configured.
 *
 * Returns the points exactly as given. This is the correct degradation, not a
 * stub: an unsnapped GPS fix is still a usable position, and it is what the
 * system used before snapping existed.
 */
class PassthroughRoadsProvider implements RoadsProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function snap(LatLng $point): LatLng
    {
        return $point;
    }

    /**
     * @param  array<int, LatLng>  $points
     * @return array<int, LatLng>
     */
    public function snapPath(array $points): array
    {
        return array_values($points);
    }
}
