<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BR-214 — stop coordinates must fall inside the configured service area.
 *
 * The rule exists to catch data-entry errors, most commonly transposed
 * latitude and longitude. A campus stop entered the wrong way round lands
 * hundreds of kilometres away and silently breaks every geofence and ETA
 * calculation on the route, without ever failing a range check: both values
 * are individually valid coordinates.
 *
 * Applied to the latitude field; it reads the longitude from the payload so
 * one rule validates the pair.
 */
class WithinServiceArea implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  string  $longitudeField  Sibling field holding the longitude.
     */
    public function __construct(private readonly string $longitudeField = 'longitude') {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $longitude = $this->data[$this->longitudeField] ?? null;

        // The paired value is absent or non-numeric; its own rules report that.
        if (! is_numeric($value) || ! is_numeric($longitude)) {
            return;
        }

        $area = config('ctms.service_area');

        $latitudeInside = $value >= $area['min_latitude'] && $value <= $area['max_latitude'];
        $longitudeInside = $longitude >= $area['min_longitude'] && $longitude <= $area['max_longitude'];

        if ($latitudeInside && $longitudeInside) {
            return;
        }

        // If swapping the pair would put it inside, say so — that is the
        // actual mistake nine times out of ten.
        $swapWouldFit = $longitude >= $area['min_latitude'] && $longitude <= $area['max_latitude']
            && $value >= $area['min_longitude'] && $value <= $area['max_longitude'];

        $fail($swapWouldFit
            ? 'These coordinates are outside the service area. Latitude and longitude may be the wrong way round.'
            : 'These coordinates are outside the service area.');
    }
}
