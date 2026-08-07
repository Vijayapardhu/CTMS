<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * BR-267 — swap the bus, the driver, or both.
 *
 * Existence is checked here; availability, licence validity and document
 * validity are business rules re-checked in the service under a row lock, at
 * commit time rather than page-load time.
 */
class ReassignTripRequest extends FormRequest
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
            'bus_id' => ['nullable', 'uuid', 'exists:buses,id'],
            'driver_id' => ['nullable', 'uuid', 'exists:drivers,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('bus_id')) && blank($this->input('driver_id'))) {
                $validator->errors()->add('bus_id', 'Choose a bus or a driver to reassign.');
            }
        });
    }
}
