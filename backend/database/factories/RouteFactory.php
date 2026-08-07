<?php

namespace Database\Factories;

use App\Enums\RouteStatus;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
class RouteFactory extends Factory
{
    protected $model = Route::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'route_name' => "Campus Route {$number}",
            'route_code' => 'RT-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'description' => fake()->sentence(),
            'total_distance_km' => fake()->randomFloat(2, 5, 60),
            'estimated_duration_minutes' => fake()->numberBetween(20, 120),
            'status' => RouteStatus::ACTIVE->value,
            'start_point' => fake()->streetName(),
            'end_point' => 'Main Campus Gate',
            'number_of_stops' => 0,
        ];
    }

    /**
     * A route with stops, and therefore serviceable (BR-203).
     *
     * A route with no stops cannot legally be scheduled, so any factory that
     * builds on top of a route — schedules, trips — must use this state to
     * produce valid data.
     */
    public function withStops(int $count = 3): static
    {
        return $this->afterCreating(function (Route $route) use ($count) {
            for ($sequence = 1; $sequence <= $count; $sequence++) {
                RouteStop::factory()
                    ->for($route)
                    ->atSequence($sequence)
                    ->create();
            }

            $route->syncStopCount();
        });
    }

    public function inactive(): static
    {
        return $this->state(['status' => RouteStatus::INACTIVE->value]);
    }

    public function underMaintenance(): static
    {
        return $this->state(['status' => RouteStatus::MAINTENANCE->value]);
    }
}
