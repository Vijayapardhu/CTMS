<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is gated to ADMIN; the policy re-checks in the controller.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_number' => ['required', 'string', 'max:32', 'unique:buses,registration_number'],
            'vehicle_name' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year_of_manufacture' => ['required', 'integer', 'between:1990,'.(date('Y') + 1)],
            // A bus with zero seats is meaningless; the upper bound is a
            // sanity check against a fat-fingered 5000.
            'seating_capacity' => ['required', 'integer', 'between:1,120'],
            'fuel_type' => ['required', 'string', Rule::in(['DIESEL', 'PETROL', 'CNG', 'ELECTRIC', 'HYBRID'])],
            'mileage' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'last_maintenance_date' => ['nullable', 'date', 'before_or_equal:today'],
            'next_maintenance_due' => ['nullable', 'date', 'after:today'],
            'color' => ['nullable', 'string', 'max:30'],
            'gps_device_id' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        if (is_string($this->input('registration_number'))) {
            // Registration numbers are compared for uniqueness, so they are
            // stored in one canonical form.
            $normalised['registration_number'] = strtoupper(trim($this->input('registration_number')));
        }

        if (is_string($this->input('fuel_type'))) {
            $normalised['fuel_type'] = strtoupper(trim($this->input('fuel_type')));
        }

        $this->merge($normalised);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_number.unique' => 'A bus with this registration number already exists.',
            'seating_capacity.between' => 'Seating capacity must be between 1 and 120.',
        ];
    }
}
