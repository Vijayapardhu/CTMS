<?php

namespace Database\Factories;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * An unpublished draft by default — publication is a deliberate act, and a
     * factory that published by default would make it easy to write a test
     * that passes without ever exercising the publish path.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by_id' => User::factory()->admin(),
            'title' => fake()->sentence(5),
            'content' => fake()->paragraph(),
            'target_audience' => AnnouncementAudience::ALL->value,
            'priority' => AnnouncementPriority::MEDIUM->value,
            'published_at' => null,
            'expires_at' => null,
            'is_active' => true,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subDay(),
            'is_active' => false,
        ]);
    }

    public function for_(AnnouncementAudience $audience): static
    {
        return $this->state(fn () => ['target_audience' => $audience->value]);
    }
}
