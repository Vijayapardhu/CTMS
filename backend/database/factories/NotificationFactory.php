<?php

namespace Database\Factories;

use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_key' => 'trip.started',
            'category' => NotificationCategory::TRIP->value,
            'priority' => NotificationPriority::STANDARD->value,
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'data' => [],
            // Unique per row: the table carries a (user_id, dedup_key) unique
            // index, so a fixed value here would make any factory that creates
            // two notifications for one user fail on the second.
            'dedup_key' => (string) Str::uuid7(),
            'read_at' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => ['priority' => NotificationPriority::CRITICAL->value]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    /**
     * Older than the retention window, for purge tests.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'created_at' => now()->subDays(
                (int) config('ctms.notifications.retention_days', 30) + 30,
            ),
        ]);
    }
}
