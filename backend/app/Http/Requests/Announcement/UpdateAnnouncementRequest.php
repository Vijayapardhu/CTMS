<?php

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a draft.
 *
 * Whether the announcement is still editable is decided in the service, not
 * here: it depends on a row that can change between validation and commit.
 */
class UpdateAnnouncementRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'content' => ['sometimes', 'string', 'min:10', 'max:5000'],
            'target_audience' => ['sometimes', Rule::enum(AnnouncementAudience::class)],
            'priority' => ['sometimes', Rule::enum(AnnouncementPriority::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
