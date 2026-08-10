<?php

namespace Tests\Feature\Trips;

use App\Contracts\Maps\RoutingProvider;
use App\Enums\InspectionItem;
use App\Models\Bus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Trip;
use App\Models\User;
use App\Services\Maps\Providers\GoogleRoutingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-09 — `GET /trips/{id}/eta`, and the road distance behind it.
 *
 * The distance matters as much as the time. A handset with no distance field
 * to read fell back to drawing a straight line between two coordinates and
 * calling it the journey — 24.9 km where the road is 37 km. These tests hold
 * the endpoint to reporting the same distance the ETA was computed from, and
 * to saying plainly which of the two providers produced it.
 */
class EtaTest extends TestCase
{
    use RefreshDatabase;

    /** Bengaluru, inside the configured service area. */
    private const LAT = 12.9716;

    private const LNG = 77.5946;

    /**
     * A running trip with two stops ahead of it.
     *
     * @return array{0: User, 1: Trip, 2: Route}
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

        return [$driverUser, $trip->fresh(), $route];
    }

    private function firstStopId(Route $route): string
    {
        return (string) $route->stops()->orderBy('sequence_number')->first()->id;
    }

    private function reportPosition(User $driver, Trip $trip): void
    {
        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $this->authHeader($driver))->assertSuccessful();
    }

    /**
     * Google, with a fake key — no test in this suite makes a paid call.
     */
    private function enableGoogle(): void
    {
        config([
            'services.google_maps.enabled' => true,
            'services.google_maps.key' => 'test-key-not-a-real-one',
        ]);

        // The binding reads config once, at container-build time, so that
        // switching Google off is a deployment decision rather than something
        // a request can change underneath itself. A test flipping the config
        // afterwards therefore has to rebind as well.
        $this->app->instance(RoutingProvider::class, new GoogleRoutingProvider);

        Cache::flush();
    }

    // ====================================================================
    // THE DISTANCE
    // ====================================================================

    #[Test]
    public function a_stop_ahead_reports_the_road_distance_behind_its_eta(): void
    {
        [$driver, $trip, $route] = $this->runningTrip();

        $this->reportPosition($driver, $trip);

        $stopId = $this->firstStopId($route);

        $response = $this->getJson(
            "/api/v1/trips/{$trip->id}/eta?stop_id={$stopId}",
            $this->authHeader($driver),
        )->assertOk();

        $data = $response->json('data');

        $this->assertGreaterThan(0, $data['distance_metres']);
        // The suite runs on the offline provider, so this must say so.
        $this->assertTrue($data['distance_is_estimate']);
        $this->assertSame('live', $data['basis']);
    }

    #[Test]
    public function a_google_distance_is_not_labelled_an_estimate(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response([
            ['originIndex' => 0, 'destinationIndex' => 0, 'distanceMeters' => 36964,
                'duration' => '3533s', 'condition' => 'ROUTE_EXISTS'],
            ['originIndex' => 0, 'destinationIndex' => 1, 'distanceMeters' => 48120,
                'duration' => '4600s', 'condition' => 'ROUTE_EXISTS'],
        ])]);

        [$driver, $trip, $route] = $this->runningTrip();

        $this->reportPosition($driver, $trip);

        $response = $this->getJson(
            "/api/v1/trips/{$trip->id}/eta?stop_id={$this->firstStopId($route)}",
            $this->authHeader($driver),
        )->assertOk();

        // Google's own metres, unconverted and unrounded on the way through.
        $response->assertJsonPath('data.distance_metres', 36964);
        $response->assertJsonPath('data.distance_is_estimate', false);
    }

    #[Test]
    public function a_google_outage_still_answers_but_says_it_is_estimating(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response(status: 503)]);

        [$driver, $trip, $route] = $this->runningTrip();

        $this->reportPosition($driver, $trip);

        $response = $this->getJson(
            "/api/v1/trips/{$trip->id}/eta?stop_id={$this->firstStopId($route)}",
            $this->authHeader($driver),
        )->assertOk();

        // Still a number — buses do not stop running when Google does — but
        // never one presented as though it came from the road network.
        $this->assertGreaterThan(0, $response->json('data.distance_metres'));
        $response->assertJsonPath('data.distance_is_estimate', true);
    }

    #[Test]
    public function a_trip_with_no_reported_position_has_no_distance(): void
    {
        [$driver, $trip, $route] = $this->runningTrip();

        $response = $this->getJson(
            "/api/v1/trips/{$trip->id}/eta?stop_id={$this->firstStopId($route)}",
            $this->authHeader($driver),
        )->assertOk();

        // The timetable knows when, not where. Reporting a distance here
        // would be inventing one.
        $response->assertJsonPath('data.basis', 'scheduled');
        $response->assertJsonPath('data.distance_metres', null);
        $response->assertJsonPath('data.distance_is_estimate', null);
    }

    #[Test]
    public function an_arrived_stop_has_nothing_left_to_drive(): void
    {
        [$driver, $trip, $route] = $this->runningTrip();

        $stopId = $this->firstStopId($route);

        $this->postJson(
            "/api/v1/trips/{$trip->id}/stops/{$stopId}/arrive",
            [],
            $this->authHeader($driver),
        )->assertOk();

        $response = $this->getJson(
            "/api/v1/trips/{$trip->id}/eta?stop_id={$stopId}",
            $this->authHeader($driver),
        )->assertOk();

        $response->assertJsonPath('data.basis', 'arrived');
        $response->assertJsonPath('data.distance_metres', 0);
        $response->assertJsonPath('data.distance_is_estimate', false);
    }

    // ====================================================================
    // WHO MAY ASK
    // ====================================================================

    #[Test]
    public function an_estimate_requires_a_token(): void
    {
        [, $trip, $route] = $this->runningTrip();

        $this->getJson("/api/v1/trips/{$trip->id}/eta?stop_id={$this->firstStopId($route)}")
            ->assertStatus(401);
    }

    #[Test]
    public function another_driver_cannot_read_this_trips_estimate(): void
    {
        [, $trip, $route] = $this->runningTrip();

        $stranger = $this->createDriver();

        $this->getJson(
            "/api/v1/trips/{$trip->id}/eta?stop_id={$this->firstStopId($route)}",
            $this->authHeader($stranger),
        )->assertStatus(403);
    }
}
