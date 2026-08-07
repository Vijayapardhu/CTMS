<?php

namespace App\Http\Requests\Schedule;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleFrequency;
use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScheduleRequest extends FormRequest
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
            'route_id' => ['sometimes', 'uuid', 'exists:routes,id'],
            'bus_id' => ['sometimes', 'uuid', 'exists:buses,id'],
            'driver_id' => ['sometimes', 'uuid', 'exists:drivers,id'],
            'departure_time' => ['sometimes', 'date_format:H:i:s'],
            'arrival_time' => ['sometimes', 'date_format:H:i:s'],
            'day_of_week' => ['sometimes', Rule::enum(DayOfWeek::class)],
            'frequency' => ['sometimes', Rule::enum(ScheduleFrequency::class)],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'expected_passenger_count' => ['sometimes', 'integer', 'min:0', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['day_of_week', 'frequency'] as $field) {
            if (is_string($this->input($field))) {
                $normalised[$field] = strtoupper(trim($this->input($field)));
            }
        }

        foreach (['departure_time', 'arrival_time'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
                $normalised[$field] = $value.':00';
            }
        }

        $this->merge($normalised);
    }

    /**
     * A partial update can still produce an invalid window — for example
     * moving only the arrival time to before the stored departure time. The
     * comparison is made against the merged result, not the payload alone.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $schedule = $this->route('id')
                ? Schedule::find($this->route('id'))
                : null;

            $departure = $this->input('departure_time', $schedule?->departure_time);
            $arrival = $this->input('arrival_time', $schedule?->arrival_time);

            if ($departure && $arrival && $arrival <= $departure) {
                $validator->errors()->add(
                    'arrival_time',
                    'The arrival time must be later than the departure time.',
                );
            }
        });
    }
}
