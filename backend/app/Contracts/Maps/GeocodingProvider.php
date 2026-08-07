<?php

namespace App\Contracts\Maps;

use App\Services\Maps\Support\LatLng;

/**
 * Geocoding API — address to coordinates and back.
 *
 * Unlike routing, this one is allowed to answer "I don't know" by returning
 * null. There is no arithmetic fallback for turning a street name into a
 * point, and inventing coordinates for a bus stop would put children in the
 * wrong place. A null here means the operator types the coordinates in.
 */
interface GeocodingProvider
{
    public function geocode(string $address): ?LatLng;

    public function reverseGeocode(LatLng $point): ?string;

    public function isAvailable(): bool;
}
