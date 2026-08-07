<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class AssignTransportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Existence is checked here. Whether the stops actually belong to the
     * chosen route, and whether they permit pickup/drop-off, is a business
     * rule enforced in StudentService.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'route_id' => ['required', 'uuid', 'exists:routes,id'],
            'pickup_stop_id' => ['required', 'uuid', 'exists:route_stops,id'],
            'dropoff_stop_id' => ['nullable', 'uuid', 'exists:route_stops,id'],
            // BR-160: seating a student beyond the route's assignable capacity
            // is permitted, but only deliberately and with a recorded reason.
            'capacity_override_reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'capacity_override_reason.min' => 'Give a meaningful reason for exceeding the route capacity.',
        ];
    }
}
