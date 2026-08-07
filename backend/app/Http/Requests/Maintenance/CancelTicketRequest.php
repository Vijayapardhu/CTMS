<?php

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling a ticket.
 *
 * A reason is required: cancelling is how a fault stops holding a bus off the
 * road, so "why" is a safety record rather than a courtesy.
 */
class CancelTicketRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
