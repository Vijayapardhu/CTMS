<?php

namespace App\Http\Requests\Trip;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-266 — reviewing an attendance disagreement.
 *
 * Note what cannot be submitted here: neither the headcount nor the boarding
 * count. A review explains a disagreement; it does not get to resolve one
 * away by adjusting the number it finds inconvenient.
 */
class ReviewDiscrepancyRequest extends FormRequest
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
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
