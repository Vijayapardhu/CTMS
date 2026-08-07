<?php

namespace App\Services\Maps\Support;

/**
 * How far and how long between two points.
 *
 * `$isEstimate` is the important field. When Google is unreachable, over
 * quota, or simply switched off, the system still answers — but it answers
 * with straight-line arithmetic, and the caller has to be able to tell. An ETA
 * presented with false confidence is worse than one labelled as a guess,
 * because somebody plans around it.
 */
final readonly class TravelEstimate
{
    public function __construct(
        public int $distanceMetres,
        public int $durationSeconds,
        public bool $isEstimate,
        public string $source,
        public ?string $polyline = null,
        public ?int $durationInTrafficSeconds = null,
    ) {}

    /**
     * Traffic-aware duration where the provider gave one, otherwise the plain
     * duration. Callers should not have to know which they got.
     */
    public function effectiveSeconds(): int
    {
        return $this->durationInTrafficSeconds ?? $this->durationSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'distance_metres' => $this->distanceMetres,
            'duration_seconds' => $this->durationSeconds,
            'duration_in_traffic_seconds' => $this->durationInTrafficSeconds,
            'is_estimate' => $this->isEstimate,
            'source' => $this->source,
        ];
    }
}
