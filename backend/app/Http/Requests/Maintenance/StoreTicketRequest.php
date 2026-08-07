<?php

namespace App\Http\Requests\Maintenance;

use App\Enums\MaintenancePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening a maintenance ticket by hand (FR-14).
 *
 * `status` is deliberately absent: a ticket always starts OPEN, and letting a
 * payload set it COMPLETED would return a bus to the road without anyone
 * looking at it (BR-358).
 */
class StoreTicketRequest extends FormRequest
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
            'issue_description' => ['required', 'string', 'min:5', 'max:2000'],
            'priority' => ['sometimes', Rule::enum(MaintenancePriority::class)],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'scheduled_date' => ['nullable', 'date'],
        ];
    }
}
