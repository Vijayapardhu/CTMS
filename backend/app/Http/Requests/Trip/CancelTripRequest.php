<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-262 — cancelling a trip requires a reason.
 *
 * The reason is not paperwork: it goes verbatim into the message every
 * affected rider receives, so "n/a" would leave a student at a stop with no
 * idea what to do next.
 */
class CancelTripRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to cancel this trip.',
            'reason.min' => 'Give a reason riders can act on — it is sent to them verbatim.',
        ];
    }
}
