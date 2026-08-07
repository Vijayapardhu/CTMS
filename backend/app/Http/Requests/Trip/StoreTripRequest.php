<?php

namespace App\Http\Requests\Trip;

use App\Models\Trip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * An ad-hoc trip — a field visit, an extra evening run.
 *
 * `status` is absent by design: a trip is always created SCHEDULED and moves
 * only through TripService, which enforces the start gate.
 */
class StoreTripRequest extends FormRequest
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
            'trip_date' => ['required', 'date', 'after_or_equal:today'],
            'scheduled_departure_time' => ['required', 'date_format:H:i:s'],
            'scheduled_arrival_time' => ['required', 'date_format:H:i:s', 'after:scheduled_departure_time'],
            'booked_seat_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            // BR-265 — required only when the date is not an operating day;
            // the service decides, since it owns the calendar.
            'override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['scheduled_departure_time', 'scheduled_arrival_time'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
                $normalised[$field] = $value.':00';
            }
        }

        $this->merge($normalised);
    }

    /**
     * A trip must not be scheduled onto a bus that already has one at the
     * same time — the conflict check schedules get, applied to ad-hoc runs.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $clash = Trip::query()
                ->unfinished()
                ->whereDate('trip_date', $this->input('trip_date'))
                ->where(function ($query) {
                    $query->where('bus_id', $this->input('bus_id'))
                        ->orWhere('driver_id', $this->input('driver_id'));
                })
                ->where('scheduled_departure_time', '<', $this->input('scheduled_arrival_time'))
                ->where('scheduled_arrival_time', '>', $this->input('scheduled_departure_time'))
                ->exists();

            if ($clash) {
                $validator->errors()->add(
                    'bus_id',
                    'This bus or driver already has a trip in that time window.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_arrival_time.after' => 'The arrival time must be later than the departure time.',
            'trip_date.after_or_equal' => 'A trip cannot be created in the past.',
        ];
    }
}
