<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\RoutingProvider;
use App\Services\Maps\Support\GoogleMapsGateway;
use App\Services\Maps\Support\LatLng;
use App\Services\Maps\Support\TravelEstimate;
use Illuminate\Support\Facades\Cache;

/**
 * Routes API and Route Matrix (FR-09).
 *
 * Never throws. When Google cannot answer — no key, timeout, quota spent,
 * breaker open — this falls back to great-circle distance at an assumed road
 * speed and marks the result `isEstimate`. Buses keep running when Google
 * stops, so the ETA pipeline has to as well.
 */
class GoogleRoutingProvider implements RoutingProvider
{
    private const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    private const MATRIX_URL = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';

    private readonly GoogleMapsGateway $gateway;

    public function __construct()
    {
        $this->gateway = new GoogleMapsGateway('routing');
    }

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable();
    }

    /**
     * @param  array<int, LatLng>  $waypoints
     */
    public function route(LatLng $origin, LatLng $destination, array $waypoints = []): TravelEstimate
    {
        $key = $this->cacheKey('route', $origin, [$destination, ...$waypoints]);

        $cached = Cache::get($key);

        if ($cached instanceof TravelEstimate) {
            return $cached;
        }

        $body = $this->gateway->post(self::ROUTES_URL, [
            'origin' => $this->waypoint($origin),
            'destination' => $this->waypoint($destination),
            'intermediates' => array_map(fn (LatLng $p) => $this->waypoint($p), $waypoints),
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
        ], [
            // Google bills by the fields requested, so asking for exactly
            // three is a cost decision as much as a payload one.
            'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
            'Content-Type' => 'application/json',
        ]);

        $leg = $body['routes'][0] ?? null;

        if ($leg === null) {
            return $this->offlineEstimate($origin, $destination, $waypoints);
        }

        $estimate = new TravelEstimate(
            distanceMetres: (int) ($leg['distanceMeters'] ?? 0),
            durationSeconds: $this->parseDuration($leg['duration'] ?? null),
            isEstimate: false,
            source: 'google.routes',
            polyline: $leg['polyline']['encodedPolyline'] ?? null,
        );

        Cache::put($key, $estimate, $this->ttl());

        return $estimate;
    }

    /**
     * @param  array<int, LatLng>  $destinations
     * @return array<int, TravelEstimate>
     */
    public function matrix(LatLng $origin, array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        $key = $this->cacheKey('matrix', $origin, $destinations);

        $cached = Cache::get($key);

        if (is_array($cached) && count($cached) === count($destinations)) {
            return $cached;
        }

        $body = $this->gateway->post(self::MATRIX_URL, [
            'origins' => [['waypoint' => $this->waypoint($origin)]],
            'destinations' => array_map(
                fn (LatLng $p) => ['waypoint' => $this->waypoint($p)],
                array_values($destinations),
            ),
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
        ], [
            'X-Goog-FieldMask' => 'originIndex,destinationIndex,distanceMeters,duration,condition',
            'Content-Type' => 'application/json',
        ]);

        if ($body === null) {
            return $this->offlineMatrix($origin, $destinations);
        }

        // The matrix response is a flat list carrying its own indices, and is
        // not guaranteed to arrive in request order.
        $estimates = $this->offlineMatrix($origin, $destinations);

        foreach ($body as $element) {
            if (! is_array($element) || ! isset($element['destinationIndex'])) {
                continue;
            }

            if (($element['condition'] ?? 'ROUTE_EXISTS') !== 'ROUTE_EXISTS') {
                // Leave the offline estimate in place for an unreachable
                // destination rather than reporting zero distance.
                continue;
            }

            $estimates[(int) $element['destinationIndex']] = new TravelEstimate(
                distanceMetres: (int) ($element['distanceMeters'] ?? 0),
                durationSeconds: $this->parseDuration($element['duration'] ?? null),
                isEstimate: false,
                source: 'google.route_matrix',
            );
        }

        Cache::put($key, $estimates, $this->ttl());

        return $estimates;
    }

    // ========================================================================
    // FALLBACK
    // ========================================================================

    /**
     * @param  array<int, LatLng>  $waypoints
     */
    private function offlineEstimate(LatLng $origin, LatLng $destination, array $waypoints = []): TravelEstimate
    {
        $points = [$origin, ...array_values($waypoints), $destination];
        $metres = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $metres += $points[$i]->metresTo($points[$i + 1]);
        }

        // Straight lines understate road distance. The multiplier is a
        // deliberate, configurable fudge: an ETA that is slightly pessimistic
        // is survivable, one that is optimistic leaves somebody at a stop
        // believing they have missed the bus.
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
    private function offlineMatrix(LatLng $origin, array $destinations): array
    {
        $out = [];

        foreach (array_values($destinations) as $index => $destination) {
            $out[$index] = $this->offlineEstimate($origin, $destination);
        }

        return $out;
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * @return array<string, mixed>
     */
    private function waypoint(LatLng $point): array
    {
        return ['location' => ['latLng' => [
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
        ]]];
    }

    /**
     * Google returns durations as a string of seconds, e.g. "412s".
     */
    private function parseDuration(?string $duration): int
    {
        return $duration === null ? 0 : (int) rtrim($duration, 's');
    }

    /**
     * @param  array<int, LatLng>  $points
     */
    private function cacheKey(string $kind, LatLng $origin, array $points): string
    {
        $parts = array_map(fn (LatLng $p) => $p->cacheKey(), array_values($points));

        return "maps:routing:{$kind}:".md5($origin->cacheKey().'|'.implode('|', $parts));
    }

    private function ttl(): \DateTimeInterface
    {
        // Short, because the whole point of TRAFFIC_AWARE is that it changes.
        return now()->addSeconds((int) config('services.google_maps.cache.routing_seconds', 120));
    }
}
