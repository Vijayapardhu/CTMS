<?php

namespace Database\Factories;

use App\Enums\NotificationCategory;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => NotificationCategory::TRIP->value,
            'channels' => ['PUSH', 'IN_APP'],
            'muted' => false,
        ];
    }

    public function forCategory(NotificationCategory $category): static
    {
        return $this->state(['category' => $category->value]);
    }

    public function muted(): static
    {
        return $this->state(['muted' => true]);
    }
}
