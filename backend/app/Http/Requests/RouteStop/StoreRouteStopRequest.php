<?php

namespace App\Http\Requests\RouteStop;

use App\Enums\StopType;
use App\Rules\WithinServiceArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `route_id` is taken from the URL, never the body — accepting it in the
 * payload would let a caller attach a stop to a route they were not
 * authorized against.
 */
class StoreRouteStopRequest extends FormRequest
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
            'stop_name' => ['required', 'string', 'max:150'],
            // Optional: omitted means "append to the end of the route".
            'sequence_number' => ['nullable', 'integer', 'min:1', 'max:200'],
            // BR-214: the pair must also fall inside the service area, which
            // catches transposed coordinates that pass both range checks.
            'latitude' => ['required', 'numeric', 'between:-90,90', new WithinServiceArea],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:150'],
            'distance_from_start_km' => ['required', 'integer', 'min:0', 'max:1000'],
            'estimated_arrival_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'waiting_time_minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
            'stop_type' => ['nullable', Rule::enum(StopType::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('stop_type'))) {
            $this->merge(['stop_type' => strtoupper(trim($this->input('stop_type')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.',
        ];
    }
}
