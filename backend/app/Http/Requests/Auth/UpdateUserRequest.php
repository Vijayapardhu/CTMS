<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Profile update payload.
 *
 * SECURITY: `role` and `is_active` are intentionally not accepted here.
 * Privilege and account state are changed through dedicated, admin-only,
 * audited endpoints — never as a side effect of a profile edit.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Object-level authorization (self vs admin) is enforced by the
        // controller against the target user, which is not known here.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The route parameter is `{id}`. Getting this name wrong silently
        // disables the ignore clause, and the user can no longer save their
        // own record without changing their email.
        $userId = $this->route('id');

        return [
            'email' => [
                'sometimes', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone_number' => [
                'sometimes', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/',
                Rule::unique('users', 'phone_number')->ignore($userId),
            ],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in(['MALE', 'FEMALE', 'OTHER'])],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }

        if (is_string($this->input('gender'))) {
            $this->merge(['gender' => strtoupper(trim($this->input('gender')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists.',
            'phone_number.unique' => 'An account with this phone number already exists.',
        ];
    }
}
