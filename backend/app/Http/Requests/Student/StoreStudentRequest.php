<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
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
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'registration_number' => ['required', 'string', 'max:50', 'unique:students,registration_number'],
            'department' => ['required', 'string', 'max:100'],
            'year_of_study' => ['required', 'integer', 'between:1,6'],
            'hostel_name' => ['nullable', 'string', 'max:100'],
            'hostel_room' => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/'],
            'has_valid_ticket' => ['nullable', 'boolean'],
            'ticket_expiry_date' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_number.unique' => 'This registration number is already in use.',
        ];
    }
}
