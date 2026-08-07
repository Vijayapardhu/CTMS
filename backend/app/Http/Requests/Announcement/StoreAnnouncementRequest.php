<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Drafting an announcement.
 *
 * `published_at`, `is_active` and `created_by_id` are all absent. A payload
 * that could set them could publish in somebody else's name, backdated, to an
 * audience of its choosing.
 */
class StoreAnnouncementRequest extends FormRequest
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
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'content' => ['required', 'string', 'min:10', 'max:5000'],
            'target_audience' => ['sometimes', Rule::enum(AnnouncementAudience::class)],
            'priority' => ['sometimes', Rule::enum(AnnouncementPriority::class)],
            // An expiry in the past would publish something already invisible.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.after' => 'An announcement cannot expire before it is published.',
        ];
    }
}
