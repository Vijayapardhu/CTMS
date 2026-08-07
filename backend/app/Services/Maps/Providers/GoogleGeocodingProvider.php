<?php

namespace App\Services\Maps\Providers;

use App\Contracts\Maps\GeocodingProvider;
use App\Services\Maps\Support\GoogleMapsGateway;
use App\Services\Maps\Support\LatLng;
use Illuminate\Support\Facades\Cache;

/**
 * Geocoding API.
 *
 * Returns null rather than guessing. There is no arithmetic fallback for
 * turning a street name into a point, and inventing coordinates for a bus stop
 * would put children somewhere the bus does not go.
 */
class GoogleGeocodingProvider implements GeocodingProvider
{
    private const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    private readonly GoogleMapsGateway $gateway;

    public function __construct()
    {
        $this->gateway = new GoogleMapsGateway('geocoding');
    }

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable();
    }

    public function geocode(string $address): ?LatLng
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        $key = 'maps:geocode:'.md5(mb_strtolower($address));

        $cached = Cache::get($key);

        if ($cached instanceof LatLng) {
            return $cached;
        }

        $body = $this->gateway->get(self::URL, [
            'address' => $address,
            'region' => (string) config('services.google_maps.region', 'in'),
        ]);

        $location = $body['results'][0]['geometry']['location'] ?? null;

        if (! is_array($location)) {
            return null;
        }

        $point = new LatLng((float) $location['lat'], (float) $location['lng']);

        // Long-lived: a street does not move. This is the cheapest cache in
        // the system and the one that saves the most money.
        Cache::put($key, $point, now()->addDays(
            (int) config('services.google_maps.cache.geocode_days', 30),
        ));

        return $point;
    }

    public function reverseGeocode(LatLng $point): ?string
    {
        $key = 'maps:reverse:'.$point->cacheKey();

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $body = $this->gateway->get(self::URL, [
            'latlng' => "{$point->latitude},{$point->longitude}",
        ]);

        $address = $body['results'][0]['formatted_address'] ?? null;

        if (! is_string($address)) {
            return null;
        }

        Cache::put($key, $address, now()->addDays(
            (int) config('services.google_maps.cache.geocode_days', 30),
        ));

        return $address;
    }
}
