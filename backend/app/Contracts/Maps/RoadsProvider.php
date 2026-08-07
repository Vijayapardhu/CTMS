<?php

namespace App\Contracts\Maps;

use App\Services\Maps\Support\LatLng;

/**
 * Roads API — pulling a drifting GPS fix back onto the road.
 *
 * The fallback is the identity function: return the point unchanged. That is
 * the correct degradation. Snapping is a cosmetic improvement to a position
 * that is already good enough to act on, and a system that discards readings
 * because it could not snap them would go blind exactly when the network is
 * struggling — which is when a bus most needs watching.
 */
interface RoadsProvider
{
    public function snap(LatLng $point): LatLng;

    /**
     * Snap a run of points together, which is far more accurate than snapping
     * each in isolation because the provider can use the path's shape.
     *
     * @param  array<int, LatLng>  $points
     * @return array<int, LatLng>
     */
    public function snapPath(array $points): array;

    public function isAvailable(): bool;
}
