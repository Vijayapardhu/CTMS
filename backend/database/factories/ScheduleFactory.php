<?php

namespace Database\Factories;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleFrequency;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A schedule is only valid on a route that has stops (BR-203), so
            // the factory must produce one rather than an empty route.
            'route_id' => Route::factory()->withStops(),
            'bus_id' => Bus::factory(),
            'driver_id' => Driver::factory(),
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::WEEKDAYS->value,
            'start_date' => null,
            'end_date' => null,
            'is_active' => true,
            'expected_passenger_count' => 30,
        ];
    }

    public function onDay(DayOfWeek $day): static
    {
        return $this->state(['day_of_week' => $day->value]);
    }

    public function between(string $departure, string $arrival): static
    {
        return $this->state([
            'departure_time' => $departure,
            'arrival_time' => $arrival,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
