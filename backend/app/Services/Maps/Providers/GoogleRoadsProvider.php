<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\RoadsProvider;
use App\Services\Maps\Support\GoogleMapsGateway;
use App\Services\Maps\Support\LatLng;

/**
 * Roads API — pulling a drifting fix back onto the road.
 *
 * The fallback is the identity function. Snapping improves a position that is
 * already good enough to act on; a pipeline that discarded readings it could
 * not snap would go blind exactly when the network is struggling, which is
 * when a bus most needs watching.
 *
 * Snapped points are never cached: a bus is somewhere new every time, so a
 * cache would only ever miss while still costing memory.
 */
class GoogleRoadsProvider implements RoadsProvider
{
    private const URL = 'https://roads.googleapis.com/v1/snapToRoads';

    /**
     * How far a snap may move a point before it is rejected, in metres.
     * Beyond this the API has almost certainly matched the wrong road, and a
     * bus shown on a parallel carriageway is worse than one shown slightly off
     * the kerb.
     */
    private const MAX_SNAP_DRIFT_METRES = 60;

    private readonly GoogleMapsGateway $gateway;

    public function __construct()
    {
        $this->gateway = new GoogleMapsGateway('roads');
    }

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable();
    }

    public function snap(LatLng $point): LatLng
    {
        return $this->snapPath([$point])[0] ?? $point;
    }

    /**
     * @param  array<int, LatLng>  $points
     * @return array<int, LatLng>
     */
    public function snapPath(array $points): array
    {
        $points = array_values($points);

        if ($points === []) {
            return [];
        }

        // The API caps a request at 100 points.
        if (count($points) > 100) {
            $points = array_slice($points, -100);
        }

        $path = implode('|', array_map(
            fn (LatLng $p) => "{$p->latitude},{$p->longitude}",
            $points,
        ));

        $body = $this->gateway->get(self::URL, [
            'path' => $path,
            'interpolate' => 'false',
        ]);

        if ($body === null) {
            return $points;
        }

        $snapped = $points;

        foreach ($body['snappedPoints'] ?? [] as $entry) {
            $index = $entry['originalIndex'] ?? null;
            $location = $entry['location'] ?? null;

            if ($index === null || ! is_array($location) || ! isset($points[$index])) {
                continue;
            }

            $candidate = new LatLng(
                (float) $location['latitude'],
                (float) $location['longitude'],
            );

            // A snap that moves the bus a long way has matched the wrong road.
            if ($points[$index]->metresTo($candidate) > self::MAX_SNAP_DRIFT_METRES) {
                continue;
            }

            $snapped[$index] = $candidate;
        }

        return $snapped;
    }
}
