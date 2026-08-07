<?php

namespace Database\Factories;

use App\Enums\StopType;
use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteStop>
 */
class RouteStopFactory extends Factory
{
    protected $model = RouteStop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'stop_name' => fake()->streetName().' Stop',
            'sequence_number' => 1,
            'latitude' => fake()->latitude(12.8, 13.1),
            'longitude' => fake()->longitude(77.4, 77.8),
            'address' => fake()->address(),
            'landmark' => fake()->word(),
            'distance_from_start_km' => fake()->numberBetween(1, 40),
            'estimated_arrival_minutes' => fake()->numberBetween(5, 90),
            'waiting_time_minutes' => 5,
            'stop_type' => StopType::BOTH->value,
        ];
    }

    public function atSequence(int $sequence): static
    {
        return $this->state(['sequence_number' => $sequence]);
    }

    public function at(float $latitude, float $longitude): static
    {
        return $this->state([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    public function pickupOnly(): static
    {
        return $this->state(['stop_type' => StopType::PICKUP->value]);
    }

    public function dropoffOnly(): static
    {
        return $this->state(['stop_type' => StopType::DROPOFF->value]);
    }
}
