<?php

namespace App\Http\Requests\Bus;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusDocumentRequest extends FormRequest
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
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'document_number' => ['nullable', 'string', 'max:64'],
            'issuing_authority' => ['nullable', 'string', 'max:150'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            // A document recorded as already expired cannot put a bus back on
            // the road, and is almost always a typo in the year.
            'expires_on' => ['required', 'date', 'after:issued_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('document_type'))) {
            $this->merge(['document_type' => strtoupper(trim($this->input('document_type')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'issued_on.before_or_equal' => 'A document cannot be issued in the future.',
            'expires_on.after' => 'The expiry date must be later than the issue date.',
        ];
    }
}
