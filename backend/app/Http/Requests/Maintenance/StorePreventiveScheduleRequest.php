<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A recurring service (BG-16, BR-366).
 */
class StorePreventiveScheduleRequest extends FormRequest
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
            'bus_id' => ['required', 'uuid', 'exists:buses,id'],
            'service_name' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'interval_km' => ['nullable', 'integer', 'min:100', 'max:1000000'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'last_serviced_on' => ['nullable', 'date'],
            'last_serviced_odometer' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // A schedule with neither interval never falls due, so it is a
            // row that looks like protection and provides none.
            if ($this->input('interval_days') === null && $this->input('interval_km') === null) {
                $validator->errors()->add(
                    'interval_days',
                    'Give an interval in days, in kilometres, or both — a schedule with neither never falls due.',
                );
            }
        });
    }
}
