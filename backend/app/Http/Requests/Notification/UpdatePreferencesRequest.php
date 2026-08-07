<?php

namespace App\Http\Requests\Notification;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A user edits only their own preferences; the route resolves the
        // subject from the token, never from the payload.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categories' => ['sometimes', 'array', 'max:'.count(NotificationCategory::cases())],
            'categories.*.category' => ['required', Rule::enum(NotificationCategory::class)],
            'categories.*.channels' => ['present', 'array'],
            'categories.*.channels.*' => [Rule::enum(NotificationChannel::class)],
            'categories.*.muted' => ['sometimes', 'boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.start' => ['nullable', 'required_with:quiet_hours.end', 'date_format:H:i:s'],
            'quiet_hours.end' => ['nullable', 'required_with:quiet_hours.start', 'date_format:H:i:s'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $categories = $this->input('categories');

        if (is_array($categories)) {
            foreach ($categories as $index => $entry) {
                if (is_array($entry) && is_string($entry['category'] ?? null)) {
                    $categories[$index]['category'] = strtoupper(trim($entry['category']));
                }

                if (is_array($entry['channels'] ?? null)) {
                    $categories[$index]['channels'] = array_map(
                        fn ($channel) => is_string($channel) ? strtoupper(trim($channel)) : $channel,
                        $entry['channels'],
                    );
                }
            }

            $this->merge(['categories' => $categories]);
        }

        // Accept "22:00" as well as "22:00:00".
        foreach (['start', 'end'] as $field) {
            $value = $this->input("quiet_hours.{$field}");

            if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
                $quietHours = $this->input('quiet_hours', []);
                $quietHours[$field] = $value.':00';
                $this->merge(['quiet_hours' => $quietHours]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quiet_hours.start.required_with' => 'Quiet hours need both a start and an end time.',
            'quiet_hours.end.required_with' => 'Quiet hours need both a start and an end time.',
        ];
    }
}
