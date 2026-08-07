<?php

namespace App\Http\Requests\Schedule;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleRequest extends FormRequest
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
            'route_id' => ['required', 'uuid', 'exists:routes,id'],
            'bus_id' => ['required', 'uuid', 'exists:buses,id'],
            'driver_id' => ['required', 'uuid', 'exists:drivers,id'],
            'departure_time' => ['required', 'date_format:H:i:s'],
            // A trip that arrives before it departs is a data-entry error, not
            // an overnight route; overnight runs are modelled as two schedules.
            'arrival_time' => ['required', 'date_format:H:i:s', 'after:departure_time'],
            'day_of_week' => ['required', Rule::enum(DayOfWeek::class)],
            'frequency' => ['required', Rule::enum(ScheduleFrequency::class)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'expected_passenger_count' => ['nullable', 'integer', 'min:0', 'max:200'],
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

        // Accept "08:00" as well as "08:00:00" — clients send both.
        foreach (['departure_time', 'arrival_time'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
                $normalised[$field] = $value.':00';
            }
        }

        $this->merge($normalised);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'arrival_time.after' => 'The arrival time must be later than the departure time.',
            'departure_time.date_format' => 'Departure time must be in HH:MM or HH:MM:SS format.',
            'arrival_time.date_format' => 'Arrival time must be in HH:MM or HH:MM:SS format.',
        ];
    }
}
