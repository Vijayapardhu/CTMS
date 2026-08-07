<?php

namespace App\Http\Requests\Route;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `status` and `number_of_stops` are excluded: status has its own endpoint,
 * and the stop count is derived from the stops themselves.
 */
class UpdateRouteRequest extends FormRequest
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
        $routeId = $this->route('id');

        return [
            'route_name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('routes', 'route_name')->ignore($routeId),
            ],
            'route_code' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('routes', 'route_code')->ignore($routeId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'total_distance_km' => ['sometimes', 'numeric', 'min:0.1', 'max:1000'],
            'estimated_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'start_point' => ['sometimes', 'string', 'max:150'],
            'end_point' => ['sometimes', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('route_code'))) {
            $this->merge(['route_code' => strtoupper(trim($this->input('route_code')))]);
        }
    }
}
