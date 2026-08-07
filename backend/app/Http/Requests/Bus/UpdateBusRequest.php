<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SECURITY: `status` is not accepted here. Status changes go through
 * PATCH /buses/{id}/status, which validates the state machine. Allowing a
 * status field on a general update would let a caller jump straight from
 * BREAKDOWN to AVAILABLE and put an unrepaired bus back on the road.
 */
class UpdateBusRequest extends FormRequest
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
        $busId = $this->route('id');

        return [
            'registration_number' => [
                'sometimes', 'string', 'max:32',
                Rule::unique('buses', 'registration_number')->ignore($busId),
            ],
            'vehicle_name' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'year_of_manufacture' => ['sometimes', 'integer', 'between:1990,'.(date('Y') + 1)],
            'seating_capacity' => ['sometimes', 'integer', 'between:1,120'],
            'fuel_type' => ['sometimes', 'string', Rule::in(['DIESEL', 'PETROL', 'CNG', 'ELECTRIC', 'HYBRID'])],
            'mileage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'last_maintenance_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'next_maintenance_due' => ['sometimes', 'nullable', 'date'],
            'color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'gps_device_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        if (is_string($this->input('registration_number'))) {
            $normalised['registration_number'] = strtoupper(trim($this->input('registration_number')));
        }

        if (is_string($this->input('fuel_type'))) {
            $normalised['fuel_type'] = strtoupper(trim($this->input('fuel_type')));
        }

        $this->merge($normalised);
    }
}
