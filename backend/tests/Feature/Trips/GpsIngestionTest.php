<?php

namespace Tests\Feature\Trips;

use App\Enums\InspectionItem;
use App\Models\Bus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-07 — the GPS ingestion pipeline (BR-300 to BR-307).
 *
 * Every position runs the same sequence. These tests exercise each gate in
 * that sequence independently, because a pipeline is only as good as its
 * least-tested step.
 */
class GpsIngestionTest extends TestCase
{
    use RefreshDatabase;

    /** Bengaluru, inside the configured service area. */
    private const LAT = 12.9716;

    private const LNG = 77.5946;

    /**
     * A running trip with a driver who may report positions.
     *
     * @return array{0: User, 1: Trip}
     */
    private function runningTrip(): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $route = Route::factory()->create();
        RouteStop::factory()->for($route)->atSequence(1)
            ->at(self::LAT + 0.05, self::LNG)->create(['stop_name' => 'First']);
        RouteStop::factory()->for($route)->atSequence(2)
            ->at(self::LAT + 0.10, self::LNG)->create(['stop_name' => 'Second']);
        $route->syncStopCount();

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => $route->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertOk();

        return [$driverUser, $trip->fresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function position(array $overrides = []): array
    {
        return array_merge([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides);
    }

    // ====================================================================
    // HAPPY PATH
    // ====================================================================

    #[Test]
    public function the_assigned_driver_can_report_a_position(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        $this->assertDatabaseCount('trip_locations', 1);
    }

    #[Test]
    public function the_position_is_denormalised_onto_the_trip(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        $trip->refresh();

        $this->assertEqualsWithDelta(self::LAT, (float) $trip->current_latitude, 0.0001);
        $this->assertNotNull($trip->last_gps_update);
    }

    #[Test]
    public function speed_is_derived_from_consecutive_positions(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        $this->travel(60)->seconds();

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['latitude' => self::LAT + 0.005]),
            $this->authHeader($driver))->assertOk();

        $latest = TripLocation::latest('recorded_at')->orderByDesc('id')->first();

        $this->assertNotNull($latest->speed_kmh);
        $this->assertGreaterThan(0, (float) $latest->speed_kmh);
    }

    // ====================================================================
    // STEP 1 — TRIP MUST BE RUNNING
    // ====================================================================

    #[Test]
    public function positions_are_refused_for_a_scheduled_trip(): void
    {
        $driverUser = $this->createDriver();
        $trip = Trip::factory()->create(['driver_id' => $driverUser->driver->id]);

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driverUser))->assertStatus(409);

        $this->assertDatabaseCount('trip_locations', 0);
    }

    #[Test]
    public function positions_are_refused_for_a_completed_trip(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [], $this->authHeader($driver))
            ->assertOk();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertStatus(409);
    }

    // ====================================================================
    // STEP 2 — ONLY THE ASSIGNED DRIVER — BR-300
    // ====================================================================

    #[Test]
    public function another_driver_cannot_report_a_position(): void
    {
        [$driver, $trip] = $this->runningTrip();
        $intruder = $this->createDriver();

        // Otherwise anyone with a token can spoof a bus's location.
        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($intruder))->assertStatus(403);

        $this->assertDatabaseCount('trip_locations', 0);
    }

    #[Test]
    public function a_student_cannot_report_a_position(): void
    {
        [$driver, $trip] = $this->runningTrip();
        $student = $this->createStudent();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function an_admin_cannot_report_a_position_for_a_driver(): void
    {
        [$driver, $trip] = $this->runningTrip();
        $admin = $this->createAdmin();

        // The policy lets operations *operate* a trip, but a position claims
        // to be a physical observation from the vehicle. Nobody else can make
        // that claim.
        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function reporting_a_position_requires_authentication(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position())
            ->assertStatus(401);
    }

    // ====================================================================
    // STEP 4 — DUPLICATES — BR-307
    // ====================================================================

    #[Test]
    public function a_replayed_position_is_absorbed(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $payload = $this->position(['idempotency_key' => 'offline-batch-1']);

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $payload,
            $this->authHeader($driver))->assertOk();

        // An offline replay is normal operation, not an error.
        $this->postJson("/api/v1/trips/{$trip->id}/positions", $payload,
            $this->authHeader($driver))->assertOk();

        $this->assertDatabaseCount('trip_locations', 1);
    }

    #[Test]
    public function distinct_keys_produce_distinct_positions(): void
    {
        [$driver, $trip] = $this->runningTrip();

        foreach (['a', 'b'] as $key) {
            $this->postJson("/api/v1/trips/{$trip->id}/positions",
                $this->position(['idempotency_key' => $key]),
                $this->authHeader($driver))->assertOk();
        }

        $this->assertDatabaseCount('trip_locations', 2);
    }

    // ====================================================================
    // STEP 5 — PLAUSIBILITY — BR-301, BR-302
    // ====================================================================

    #[Test]
    public function a_position_outside_the_service_area_is_rejected(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['latitude' => 0.0, 'longitude' => 0.0]),
            $this->authHeader($driver))->assertStatus(422);

        $this->assertDatabaseCount('trip_locations', 0);
    }

    #[Test]
    public function an_implausible_jump_is_rejected(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        // Several hundred kilometres in one reading.
        $response = $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['latitude' => self::LAT + 5.0]),
            $this->authHeader($driver))->assertStatus(409);

        $this->assertStringContainsString('jump', strtolower($response->json('message')));
        // BR-301 — rejected points never become truth.
        $this->assertDatabaseCount('trip_locations', 1);
    }

    #[Test]
    public function an_implausible_speed_is_rejected(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        // ~4km in one second.
        $this->travel(1)->seconds();

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['latitude' => self::LAT + 0.035]),
            $this->authHeader($driver))->assertStatus(409);

        $this->assertDatabaseCount('trip_locations', 1);
    }

    #[Test]
    public function a_low_accuracy_reading_is_rejected(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['accuracy_meters' => 500]),
            $this->authHeader($driver))->assertStatus(409);

        $this->assertDatabaseCount('trip_locations', 0);
    }

    #[Test]
    public function a_plausible_movement_is_accepted(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        $this->travel(60)->seconds();

        // ~550m in a minute — about 33 km/h.
        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['latitude' => self::LAT + 0.005]),
            $this->authHeader($driver))->assertOk();

        $this->assertDatabaseCount('trip_locations', 2);
    }

    // ====================================================================
    // STEP 3 — CLOCK SKEW
    // ====================================================================

    #[Test]
    public function a_device_clock_far_in_the_future_is_not_trusted(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['recorded_at' => now()->addDay()->toIso8601String()]),
            $this->authHeader($driver))->assertOk();

        $location = TripLocation::first();

        // Recorded, but flagged — and the server clock is used for ordering,
        // or every later reading would look older than this one.
        $this->assertTrue($location->clock_skew_suspected);
        $this->assertTrue($location->recorded_at->isBefore(now()->addHour()));
    }

    #[Test]
    public function a_device_clock_within_tolerance_is_trusted(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $deviceTime = now()->subSeconds(5);

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['recorded_at' => $deviceTime->toIso8601String()]),
            $this->authHeader($driver))->assertOk();

        $this->assertFalse(TripLocation::first()->clock_skew_suspected);
    }

    // ====================================================================
    // VALIDATION
    // ====================================================================

    #[Test]
    public function coordinates_are_required(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", [], $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['latitude', 'longitude']]);
    }

    #[Test]
    public function an_out_of_range_coordinate_is_rejected(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions",
            $this->position(['latitude' => 200]), $this->authHeader($driver))
            ->assertStatus(422);
    }

    // ====================================================================
    // LIVE VIEW — BR-305
    // ====================================================================

    #[Test]
    public function the_live_view_reports_a_fresh_position_as_live(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        $this->getJson("/api/v1/trips/{$trip->id}/live", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.position.is_stale', false);
    }

    #[Test]
    public function the_live_view_marks_an_old_position_as_stale(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", $this->position(),
            $this->authHeader($driver))->assertOk();

        $this->travel(10)->minutes();

        // A twenty-minute-old position presented as current sends a student
        // into the road at the wrong moment.
        $this->getJson("/api/v1/trips/{$trip->id}/live", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.position.is_stale', true);
    }

    #[Test]
    public function the_live_view_reports_no_position_before_the_first_reading(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $this->getJson("/api/v1/trips/{$trip->id}/live", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.position', null);
    }

    #[Test]
    public function a_student_on_another_route_cannot_see_the_live_view(): void
    {
        [$driver, $trip] = $this->runningTrip();
        $student = $this->createStudent();

        $this->getJson("/api/v1/trips/{$trip->id}/live", $this->authHeader($student))
            ->assertStatus(403);
    }
}
