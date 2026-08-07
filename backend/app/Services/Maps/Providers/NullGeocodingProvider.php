<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\GeocodingProvider;
use App\Contracts\Maps\PlacesProvider;
use App\Services\Maps\Support\LatLng;

/**
 * Geocoding with no provider configured.
 *
 * Answers "I don't know" to everything, which is the only honest thing it can
 * say. Callers are already required to handle null, because a real geocoder
 * fails to find addresses too.
 */
class NullGeocodingProvider implements GeocodingProvider, PlacesProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function geocode(string $address): ?LatLng
    {
        return null;
    }

    public function reverseGeocode(LatLng $point): ?string
    {
        return null;
    }

    /**
     * @return array<int, array{name: string, address: string, point: LatLng, place_id: string|null}>
     */
    public function search(string $query, ?LatLng $near = null): array
    {
        return [];
    }
}
