<?php

namespace App\Http\Requests\Trip;

use App\Services\Trips\TripRecoveryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * BR-258 — a correction to a closed trip.
 *
 * The correctable field list is deliberately narrow and comes from the
 * service, not from here, so there is one place that decides what may be
 * changed after the fact. `status`, attribution and timestamps are not on it:
 * those are precisely the fields somebody would want to change to hide
 * something.
 */
class CorrectTripRequest extends FormRequest
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
            'field' => ['required', 'string', Rule::in(TripRecoveryService::correctableFields())],
            'value' => ['present', 'nullable'],
            // A correction without a stated reason is indistinguishable from
            // tampering when somebody reads it back in six months.
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
