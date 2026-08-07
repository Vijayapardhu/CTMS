<?php

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BR-502 — a bulk personal-data export requires a stated reason.
 *
 * The reason is not paperwork. It is the difference between an export that can
 * be justified when somebody asks six months later and one that reads, in the
 * access log, as an administrator taking a copy of a student's file for no
 * recorded purpose.
 */
class SubjectAccessRequest extends FormRequest
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
            'reason.required' => 'State why this export is being produced. It is recorded against your name.',
            'reason.min' => 'Give a real reason — this entry is reviewed.',
        ];
    }
}
