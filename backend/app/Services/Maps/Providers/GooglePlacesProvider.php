<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\PlacesProvider;
use App\Services\Maps\Support\GoogleMapsGateway;
use App\Services\Maps\Support\LatLng;
use Illuminate\Support\Facades\Cache;

/**
 * Places API — finding a stop by name.
 *
 * An empty list is a fine answer; the operator types an address instead.
 */
class GooglePlacesProvider implements PlacesProvider
{
    private const URL = 'https://places.googleapis.com/v1/places:searchText';

    private readonly GoogleMapsGateway $gateway;

    public function __construct()
    {
        $this->gateway = new GoogleMapsGateway('places');
    }

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable();
    }

    /**
     * @return array<int, array{name: string, address: string, point: LatLng, place_id: string|null}>
     */
    public function search(string $query, ?LatLng $near = null): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 3) {
            // Anything shorter matches half the country and costs a call to
            // find that out.
            return [];
        }

        $key = 'maps:places:'.md5(mb_strtolower($query).'|'.($near?->cacheKey() ?? ''));

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $payload = ['textQuery' => $query];

        if ($near !== null) {
            $payload['locationBias'] = ['circle' => [
                'center' => ['latitude' => $near->latitude, 'longitude' => $near->longitude],
                'radius' => (float) config('services.google_maps.places.bias_radius_metres', 30000),
            ]];
        }

        $body = $this->gateway->post(self::URL, $payload, [
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location',
            'Content-Type' => 'application/json',
        ]);

        $results = [];

        foreach ($body['places'] ?? [] as $place) {
            $location = $place['location'] ?? null;

            if (! is_array($location)) {
                continue;
            }

            $results[] = [
                'name' => $place['displayName']['text'] ?? ($place['formattedAddress'] ?? 'Unnamed place'),
                'address' => $place['formattedAddress'] ?? '',
                'point' => new LatLng((float) $location['latitude'], (float) $location['longitude']),
                'place_id' => $place['id'] ?? null,
            ];
        }

        Cache::put($key, $results, now()->addHours(
            (int) config('services.google_maps.cache.places_hours', 24),
        ));

        return $results;
    }
}
