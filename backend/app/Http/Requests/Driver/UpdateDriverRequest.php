<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SECURITY: `status`, `assigned_bus_id` and `user_id` are not accepted here.
 * Duty status and bus assignment have their own endpoints with their own
 * rules; re-pointing a driver profile at a different user account is never a
 * legitimate edit.
 */
class UpdateDriverRequest extends FormRequest
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
        $driverId = $this->route('id');

        return [
            'license_number' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('drivers', 'license_number')->ignore($driverId),
            ],
            'license_class' => ['sometimes', 'string', 'max:20'],
            // Renewals may be recorded, but never to a date already past.
            'license_expiry_date' => ['sometimes', 'date', 'after:today'],
            'violations_history' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
