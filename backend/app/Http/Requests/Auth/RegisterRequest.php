<?php

namespace App\Http\Requests\Auth;

use App\Enums\AccessLevel;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registration payload.
 *
 * SECURITY: role selection is privilege-sensitive. Self-service registration
 * may only ever create a STUDENT. Creating a DRIVER or ADMIN requires an
 * authenticated administrator, otherwise anyone could grant themselves the
 * keys to the fleet by posting `"role": "ADMIN"`.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requestedRole = $this->requestedRole();

        // An unrecognised role falls through to rules() for a clean 422.
        if ($requestedRole === null || $requestedRole === UserRole::STUDENT) {
            return true;
        }

        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/', 'unique:users,phone_number'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()->symbols()],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::enum(UserRole::class)],
        ];

        return array_merge($rules, match ($this->requestedRole()) {
            UserRole::STUDENT => [
                'registration_number' => ['required', 'string', 'max:50', 'unique:students,registration_number'],
                'department' => ['required', 'string', 'max:100'],
                'year_of_study' => ['required', 'integer', 'between:1,6'],
                'hostel_name' => ['nullable', 'string', 'max:100'],
                'hostel_room' => ['nullable', 'string', 'max:20'],
                'emergency_contact' => ['nullable', 'string', 'max:255'],
                'emergency_contact_phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{7,15}$/'],
            ],
            UserRole::DRIVER => [
                'license_number' => ['required', 'string', 'max:50', 'unique:drivers,license_number'],
                'license_class' => ['required', 'string', 'max:20'],
                'license_expiry_date' => ['required', 'date', 'after:today'],
            ],
            UserRole::ADMIN => [
                'designation' => ['required', 'string', 'max:100'],
                'department' => ['required', 'string', 'max:100'],
                'access_level' => ['nullable', Rule::enum(AccessLevel::class)],
            ],
            default => [],
        });
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        if (is_string($this->input('email'))) {
            $normalised['email'] = strtolower(trim($this->input('email')));
        }

        // Roles are canonical uppercase; accept any casing at the edge and
        // normalise once, here, rather than case-folding at comparison time.
        if (is_string($this->input('role'))) {
            $normalised['role'] = strtoupper(trim($this->input('role')));
        }

        if (is_string($this->input('access_level'))) {
            $normalised['access_level'] = strtoupper(trim($this->input('access_level')));
        }

        $this->merge($normalised);
    }

    /**
     * The role this payload is asking for.
     */
    public function requestedRole(): ?UserRole
    {
        return UserRole::tryFrom((string) $this->input('role'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email address already exists.',
            'phone_number.unique' => 'An account with this phone number already exists.',
            'phone_number.regex' => 'Please provide a valid phone number.',
            'password.confirmed' => 'The password confirmation does not match.',
            'license_expiry_date.after' => 'The driving licence must not already be expired.',
            'registration_number.unique' => 'This registration number is already in use.',
            'license_number.unique' => 'This licence number is already in use.',
        ];
    }
}
