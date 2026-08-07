<?php

namespace App\Http\Requests\RouteStop;

use App\Enums\StopType;
use App\Models\RouteStop;
use App\Rules\WithinServiceArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRouteStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stop_name' => ['sometimes', 'string', 'max:150'],
            'sequence_number' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90', new WithinServiceArea],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'address' => ['sometimes', 'string', 'max:255'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:150'],
            'distance_from_start_km' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'estimated_arrival_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'waiting_time_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'stop_type' => ['sometimes', Rule::enum(StopType::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('stop_type'))) {
            $this->merge(['stop_type' => strtoupper(trim($this->input('stop_type')))]);
        }

        // Latitude and longitude are validated as a pair (BR-214). A partial
        // update supplying only one of them must be checked against the stored
        // value of the other, or a stop could be walked outside the service
        // area one coordinate at a time.
        $this->mergeMissingCoordinateFromStoredStop();
    }

    private function mergeMissingCoordinateFromStoredStop(): void
    {
        $hasLatitude = $this->has('latitude');
        $hasLongitude = $this->has('longitude');

        if ($hasLatitude === $hasLongitude) {
            return; // Both present, or neither — nothing to reconcile.
        }

        $stop = RouteStop::find($this->route('stopId'));

        if (! $stop) {
            return; // Unknown stop; the controller returns a 404.
        }

        $this->merge($hasLatitude
            ? ['longitude' => $stop->longitude]
            : ['latitude' => $stop->latitude]);
    }
}
