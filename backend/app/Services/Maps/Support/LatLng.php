<?php

namespace App\Services\Maps\Support;

/**
 * A point on the earth.
 *
 * A tiny value object rather than a pair of floats passed around, because
 * `(float $lat, float $lng)` and `(float $lng, float $lat)` have the same
 * signature and different meanings, and the mistake is silent.
 */
final readonly class LatLng
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public static function make(float $latitude, float $longitude): self
    {
        return new self($latitude, $longitude);
    }

    /**
     * Great-circle distance in metres.
     *
     * This is the fallback every provider degrades to, so it lives here rather
     * than being copied into each one.
     */
    public function metresTo(self $other): float
    {
        $earthRadius = 6_371_000;

        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad($other->longitude) - deg2rad($this->longitude);

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * A stable key for caching. Rounded to about 11 metres, which is finer
     * than any decision made from it and coarse enough that a bus creeping
     * forward does not miss the cache on every reading.
     */
    public function cacheKey(): string
    {
        return sprintf('%.4f,%.4f', $this->latitude, $this->longitude);
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function toArray(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
