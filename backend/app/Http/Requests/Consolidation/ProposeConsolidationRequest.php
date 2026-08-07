<?php

namespace App\Http\Requests\Consolidation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual consolidation proposal.
 *
 * The viability checks — capacity, shared stops, same day, divergence point —
 * live in the service, not here. They depend on rows that can change between
 * validation and commit, and a rule enforced only at the edge is a rule that
 * can be raced.
 */
class ProposeConsolidationRequest extends FormRequest
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
            'source_trip_id' => ['required', 'uuid', 'exists:trips,id', 'different:target_trip_id'],
            'target_trip_id' => ['required', 'uuid', 'exists:trips,id'],
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_trip_id.different' => 'A trip cannot be merged into itself.',
        ];
    }
}
