<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is behind auth.jwt; a user may only change their own password.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                Password::min(8)->letters()->numbers()->symbols(),
            ],
        ];
    }

    /**
     * Verify the current password by hash comparison.
     *
     * Done here rather than with the `current_password` rule because that rule
     * resolves the default session guard, which this stateless API does not use.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            $current = $this->input('current_password');

            if ($user && is_string($current) && ! Hash::check($current, $user->password)) {
                $validator->errors()->add('current_password', 'The current password is incorrect.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.different' => 'The new password must be different from the current one.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
