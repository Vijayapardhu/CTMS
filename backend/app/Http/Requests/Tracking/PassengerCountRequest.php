<?php

namespace App\Http\Requests\Tracking;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A boarding or alighting.
 *
 * `student_id` is optional: headcount mode counts heads, named mode identifies
 * people. Which an institution uses determines whether "your child boarded" is
 * deliverable at all — see the open decisions in the system map.
 */
class PassengerCountRequest extends FormRequest
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
            'student_id' => ['nullable', 'uuid', 'exists:students,id'],
            'route_stop_id' => ['nullable', 'uuid', 'exists:route_stops,id'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function student(): ?Student
    {
        $id = $this->validated('student_id');

        return $id === null ? null : Student::with('user')->find($id);
    }
}
