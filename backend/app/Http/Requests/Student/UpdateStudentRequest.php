<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transport assignment fields and `status` are excluded: both have dedicated,
 * rule-checked endpoints. Notably `has_valid_ticket` is admin-only input —
 * see StudentController, which strips it for non-admin callers.
 */
class UpdateStudentRequest extends FormRequest
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
        $studentId = $this->route('id');

        return [
            'registration_number' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('students', 'registration_number')->ignore($studentId),
            ],
            'department' => ['sometimes', 'string', 'max:100'],
            'year_of_study' => ['sometimes', 'integer', 'between:1,6'],
            'hostel_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'hostel_room' => ['sometimes', 'nullable', 'string', 'max:20'],
            'emergency_contact' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/'],
            'has_valid_ticket' => ['sometimes', 'boolean'],
            'ticket_expiry_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
