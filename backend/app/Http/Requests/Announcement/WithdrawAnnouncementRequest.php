<?php

namespace App\Http\Requests\Announcement;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking an announcement down.
 *
 * A reason is required. The notifications already sent cannot be recalled, so
 * the record of why it was pulled is the only thing that explains the
 * discrepancy to somebody who read it.
 */
class WithdrawAnnouncementRequest extends FormRequest
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
