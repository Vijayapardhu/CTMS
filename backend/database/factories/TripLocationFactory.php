<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripLocation>
 */
class TripLocationFactory extends Factory
{
    protected $model = TripLocation::class;

    /**
     * A plausible reading somewhere in the service area.
     *
     * The coordinates stay near the campus so a generated trace does not look
     * like a bus teleporting across the state — which the plausibility gate
     * would rightly reject if it were ever fed back through ingestion.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recordedAt = fake()->dateTimeBetween('-2 hours', 'now');

        return [
            'trip_id' => Trip::factory(),
            'latitude' => fake()->randomFloat(6, 17.40, 17.52),
            'longitude' => fake()->randomFloat(6, 78.40, 78.55),
            'speed_kmh' => fake()->randomFloat(1, 0, 55),
            'heading' => fake()->randomFloat(1, 0, 359),
            'accuracy_meters' => fake()->randomFloat(1, 3, 20),
            'recorded_at' => $recordedAt,
            'device_recorded_at' => $recordedAt,
            'clock_skew_suspected' => false,
        ];
    }

    /**
     * A reading old enough to be past the retention window (BR-307).
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'recorded_at' => now()->subDays(
                (int) config('ctms.retention.location_trace_days', 90) + 30,
            ),
        ]);
    }
}
