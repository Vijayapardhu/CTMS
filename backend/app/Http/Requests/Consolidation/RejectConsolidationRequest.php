<?php

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejecting a proposal.
 *
 * A reason is required. "Rejected" with no explanation tells the next person
 * looking at the same pair of half-empty buses nothing at all, and they will
 * propose it again.
 */
class RejectConsolidationRequest extends FormRequest
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
