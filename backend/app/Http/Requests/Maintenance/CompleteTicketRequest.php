<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-358 — signing off maintenance.
 *
 * A resolution account is required. This is the record that justifies putting
 * a vehicle back under passengers, and "completed" on its own justifies
 * nothing.
 */
class CompleteTicketRequest extends FormRequest
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
            'resolution_notes' => ['required', 'string', 'min:5', 'max:2000'],
            'actual_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'parts_used' => ['nullable', 'string', 'max:2000'],
            // Checked against the bus's running total (BR-061) in the service,
            // not here: the comparison needs a row that can change between
            // validation and commit.
            'odometer_reading' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ];
    }
}
