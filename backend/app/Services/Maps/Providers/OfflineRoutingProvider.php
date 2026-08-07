<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\RoutingProvider;
use App\Services\Maps\Support\LatLng;
use App\Services\Maps\Support\TravelEstimate;

/**
 * Routing with no external dependency at all.
 *
 * This is the default binding, and the one the whole test suite runs against.
 * Two consequences worth stating plainly: the tests never make a network call
 * or spend a rupee, and — more importantly — the offline path is the one
 * getting exercised on every run, so the behaviour the system falls back to
 * during a Google outage is the behaviour that is actually tested.
 */
class OfflineRoutingProvider implements RoutingProvider
{
    public function isAvailable(): bool
    {
        // False, and deliberately so. The contract asks whether this provider
        // can answer *for real*, not whether it can answer at all — it always
        // answers. Reporting true here would tell operations their ETAs are
        // live when every one of them is straight-line arithmetic.
        return false;
    }

    /**
     * @param  array<int, LatLng>  $waypoints
     */
    public function route(LatLng $origin, LatLng $destination, array $waypoints = []): TravelEstimate
    {
        $points = [$origin, ...array_values($waypoints), $destination];
        $metres = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $metres += $points[$i]->metresTo($points[$i + 1]);
        }

        $metres *= (float) config('services.google_maps.fallback.road_factor', 1.3);

        $speedKmh = (float) config('services.google_maps.fallback.speed_kmh', 25);
        $seconds = $speedKmh > 0 ? (int) round(($metres / 1000) / $speedKmh * 3600) : 0;

        return new TravelEstimate(
            distanceMetres: (int) round($metres),
            durationSeconds: $seconds,
            isEstimate: true,
            source: 'offline.haversine',
        );
    }

    /**
     * @param  array<int, LatLng>  $destinations
     * @return array<int, TravelEstimate>
     */
    public function matrix(LatLng $origin, array $destinations): array
    {
        $out = [];

        foreach (array_values($destinations) as $index => $destination) {
            $out[$index] = $this->route($origin, $destination);
        }

        return $out;
    }
}
