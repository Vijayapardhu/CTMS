<?php

namespace App\Http\Requests\Route;

use Illuminate\Foundation\Http\FormRequest;

class StoreRouteRequest extends FormRequest
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
            'route_name' => ['required', 'string', 'max:100', 'unique:routes,route_name'],
            'route_code' => ['required', 'string', 'max:20', 'unique:routes,route_code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'total_distance_km' => ['required', 'numeric', 'min:0.1', 'max:1000'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'start_point' => ['required', 'string', 'max:150'],
            'end_point' => ['required', 'string', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('route_code'))) {
            $this->merge(['route_code' => strtoupper(trim($this->input('route_code')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'route_code.unique' => 'A route with this code already exists.',
            'route_name.unique' => 'A route with this name already exists.',
        ];
    }
}
