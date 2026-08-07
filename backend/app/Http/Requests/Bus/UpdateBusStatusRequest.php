<?php

namespace App\Http\Requests\Bus;

use App\Enums\BusStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusStatusRequest extends FormRequest
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
            // Validated against the enum itself, so adding a state to the enum
            // cannot leave this rule behind.
            'status' => ['required', Rule::enum(BusStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => strtoupper(trim($this->input('status')))]);
        }
    }

    public function status(): BusStatus
    {
        return BusStatus::from($this->validated('status'));
    }
}
