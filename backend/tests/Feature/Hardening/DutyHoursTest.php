<?php

namespace Tests\Feature\Hardening;

use App\Enums\InspectionItem;
use App\Enums\TripStatus;
use App\Models\Bus;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-106 — duty-hour ceilings.
 *
 * A driver will not tell you they are too tired on the morning a colleague has
 * called in sick. The roster has to refuse for them.
 */
class DutyHoursTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A driver with a fresh bus, ready to start a trip.
     *
     * @return array{0: User, 1: Trip}
     */
    private function readyToStart(User $driverUser, int $odometer = 10000): array
    {
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => $odometer,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        return [$driverUser, $trip];
    }

    /**
     * Record a completed trip the driver already drove today.
     */
    private function alreadyDrove(User $driverUser, string $from, string $to): void
    {
        Trip::factory()->create([
            'bus_id' => Bus::factory()->withCapacity(40)->create()->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
            'trip_date' => today(),
            'status' => TripStatus::COMPLETED->value,
            'actual_departure_time' => $from,
            'actual_arrival_time' => $to,
        ]);
    }

    #[Test]
    public function a_fresh_driver_can_start(): void
    {
        $driver = $this->createDriver();
        [, $trip] = $this->readyToStart($driver);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    #[Test]
    public function a_driver_over_the_daily_ceiling_is_refused(): void
    {
        $driver = $this->createDriver();

        // Nine hours already driven today, in blocks with real breaks between
        // them so only the daily ceiling is in play.
        $this->alreadyDrove($driver, '05:00:00', '09:00:00');
        $this->alreadyDrove($driver, '10:00:00', '15:00:00');

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409)
            ->assertJsonPath('errors.duty_breaches', fn ($b) => count($b) > 0);
    }

    #[Test]
    public function a_driver_under_the_daily_ceiling_still_runs(): void
    {
        $driver = $this->createDriver();

        $this->alreadyDrove($driver, '06:00:00', '09:00:00');

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    #[Test]
    public function continuous_driving_without_a_break_is_refused(): void
    {
        $driver = $this->createDriver();

        // Four and a half hours back to back — under the daily ceiling, over
        // the continuous one. A break is what resets the run, and there was
        // not one.
        $this->alreadyDrove($driver, '05:00:00', '07:20:00');
        $this->alreadyDrove($driver, '07:30:00', '10:00:00');

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function a_qualifying_break_resets_the_continuous_run(): void
    {
        $driver = $this->createDriver();

        // The same total driving, but with a real break in the middle.
        $this->alreadyDrove($driver, '05:00:00', '07:20:00');
        $this->alreadyDrove($driver, '08:30:00', '10:00:00');

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    #[Test]
    public function the_refusal_says_which_ceiling_was_hit(): void
    {
        $driver = $this->createDriver();

        $this->alreadyDrove($driver, '05:00:00', '09:00:00');
        $this->alreadyDrove($driver, '10:00:00', '15:00:00');

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $response = $this->postJson("/api/v1/trips/{$trip->id}/start", [],
            $this->authHeader($driver))->assertStatus(409);

        // A refusal a dispatcher cannot act on is a refusal they will work
        // around.
        $this->assertStringContainsString('Daily driving limit', implode(' ', $response->json('errors.duty_breaches')));
    }

    #[Test]
    public function yesterdays_driving_does_not_count_against_today(): void
    {
        $driver = $this->createDriver();

        Trip::factory()->create([
            'bus_id' => Bus::factory()->withCapacity(40)->create()->id,
            'driver_id' => $driver->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
            'trip_date' => today()->subDay(),
            'status' => TripStatus::COMPLETED->value,
            'actual_departure_time' => '05:00:00',
            'actual_arrival_time' => '15:00:00',
        ]);

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    #[Test]
    public function a_short_rest_since_yesterdays_duty_is_refused(): void
    {
        $driver = $this->createDriver();

        // Finished at 23:00 last night. Anything before roughly 09:00 today
        // is inside the ten-hour rest window.
        $this->travelTo(today()->setTime(3, 0));

        Trip::factory()->create([
            'bus_id' => Bus::factory()->withCapacity(40)->create()->id,
            'driver_id' => $driver->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
            'trip_date' => today()->subDay(),
            'status' => TripStatus::COMPLETED->value,
            'actual_departure_time' => '18:00:00',
            'actual_arrival_time' => '23:00:00',
        ]);

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function a_second_run_of_the_same_morning_is_not_treated_as_broken_rest(): void
    {
        $driver = $this->createDriver();

        // A short first run that has just finished. The gap before the next
        // one is a break, governed by the continuous ceiling — not rest.
        $this->alreadyDrove($driver, '07:00:00', '07:40:00');

        [, $trip] = $this->readyToStart($driver, odometer: 20000);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }
}
