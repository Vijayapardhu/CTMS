<?php

namespace Database\Factories;

use App\Enums\TripStatus;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    protected $model = Trip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => Schedule::factory(),
            'bus_id' => Bus::factory(),
            'driver_id' => Driver::factory(),
            'route_id' => Route::factory()->withStops(),
            'trip_date' => now()->toDateString(),
            'scheduled_departure_time' => '08:00:00',
            'scheduled_arrival_time' => '09:00:00',
            'status' => TripStatus::SCHEDULED->value,
            'booked_seat_count' => 20,
            'occupied_seat_count' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state([
            'status' => TripStatus::RUNNING->value,
            'actual_departure_time' => '08:02:00',
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => TripStatus::COMPLETED->value,
            'actual_departure_time' => '08:02:00',
            'actual_arrival_time' => '09:05:00',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => TripStatus::CANCELLED->value,
            'cancellation_reason' => 'Cancelled in test setup.',
            'cancelled_at' => now(),
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(['trip_date' => $date]);
    }

    /**
     * Departing within the start window, so a start attempt is not refused
     * for timing reasons.
     */
    public function departingNow(): static
    {
        return $this->state([
            'trip_date' => now()->toDateString(),
            'scheduled_departure_time' => now()->format('H:i:s'),
            'scheduled_arrival_time' => now()->addHour()->format('H:i:s'),
        ]);
    }
}
