<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class AssignBusRequest extends FormRequest
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
            // Existence is checked here; availability, licence validity and
            // "not already taken" are business rules checked in the service
            // under a row lock.
            'bus_id' => ['required', 'uuid', 'exists:buses,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bus_id.exists' => 'The selected bus does not exist.',
        ];
    }
}
