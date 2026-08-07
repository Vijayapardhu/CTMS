<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->driver(),
            'license_number' => 'DL-'.fake()->unique()->regexify('[A-Z0-9]{10}'),
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYears(2)->toDateString(),
            'status' => DriverStatus::AVAILABLE->value,
            'current_latitude' => 12.9716,
            'current_longitude' => 77.5946,
            'last_gps_update' => now(),
        ];
    }

    public function onTrip(): static
    {
        return $this->state(['status' => DriverStatus::ON_TRIP->value]);
    }

    public function onLeave(): static
    {
        return $this->state(['status' => DriverStatus::LEAVE->value]);
    }

    /**
     * A driver whose licence has already lapsed — must never be assignable.
     */
    public function licenceExpired(): static
    {
        return $this->state(['license_expiry_date' => now()->subDay()->toDateString()]);
    }
}
