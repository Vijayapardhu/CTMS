<?php

namespace App\Http\Requests\Evidence;

use App\Enums\EvidenceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading a file.
 *
 * The rules here are the cheap first pass — that something was actually
 * uploaded, and that the category is one we know. The real checks (the MIME
 * type sniffed from the bytes rather than declared, the extension, the size
 * ceiling for that category) live in EvidenceService, because they have to
 * agree with how the file is stored and there must be one place that decides.
 */
class StoreEvidenceRequest extends FormRequest
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
            'file' => ['required', 'file'],
            'category' => ['required', Rule::enum(EvidenceCategory::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'No file was received. Check the upload completed.',
        ];
    }
}
