<?php

namespace App\Contracts\Maps;

use App\Services\Maps\Support\LatLng;

/**
 * Places API — searching for a stop by name.
 *
 * A convenience for whoever is building a route. Returning an empty list is a
 * perfectly good answer; the operator falls back to typing an address.
 */
interface PlacesProvider
{
    /**
     * @return array<int, array{name: string, address: string, point: LatLng, place_id: string|null}>
     */
    public function search(string $query, ?LatLng $near = null): array;

    public function isAvailable(): bool;
}
