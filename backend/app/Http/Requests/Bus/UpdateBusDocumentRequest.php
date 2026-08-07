<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `document_type` is not editable: changing a fitness certificate into an
 * insurance record would silently alter which mandatory document the bus is
 * judged against. A wrong type is corrected by recording the right one.
 */
class UpdateBusDocumentRequest extends FormRequest
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
            'document_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'issuing_authority' => ['sometimes', 'nullable', 'string', 'max:150'],
            'issued_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'expires_on' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
