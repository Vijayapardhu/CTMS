<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
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
            // The account must exist; the service additionally verifies it
            // holds the DRIVER role and has no profile yet.
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'license_number' => ['required', 'string', 'max:50', 'unique:drivers,license_number'],
            'license_class' => ['required', 'string', 'max:20'],
            'license_expiry_date' => ['required', 'date', 'after:today'],
            'violations_history' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'license_number.unique' => 'This licence number is already registered to another driver.',
            'license_expiry_date.after' => 'The driving licence must not already be expired.',
        ];
    }
}
