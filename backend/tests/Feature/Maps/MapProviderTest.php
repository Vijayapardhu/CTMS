<?php

namespace Tests\Feature\Maps;

use App\Contracts\Maps\GeocodingProvider;
use App\Contracts\Maps\PlacesProvider;
use App\Contracts\Maps\RoadsProvider;
use App\Contracts\Maps\RoutingProvider;
use App\Services\Maps\Providers\GoogleGeocodingProvider;
use App\Services\Maps\Providers\GoogleRoadsProvider;
use App\Services\Maps\Providers\GoogleRoutingProvider;
use App\Services\Maps\Providers\NullGeocodingProvider;
use App\Services\Maps\Providers\OfflineRoutingProvider;
use App\Services\Maps\Providers\PassthroughRoadsProvider;
use App\Services\Maps\Support\LatLng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-09 — the map provider layer.
 *
 * The whole point of this layer is that a Google outage degrades the system
 * rather than stopping it, so most of these tests are outages.
 */
class MapProviderTest extends TestCase
{
    use RefreshDatabase;

    private const COLLEGE = [17.4500, 78.4500];

    private const STOP = [17.4800, 78.4900];

    private function origin(): LatLng
    {
        return LatLng::make(...self::COLLEGE);
    }

    private function destination(): LatLng
    {
        return LatLng::make(...self::STOP);
    }

    /**
     * Turn Google on for a test, with a fake key.
     */
    private function enableGoogle(): void
    {
        config([
            'services.google_maps.enabled' => true,
            'services.google_maps.key' => 'test-key-not-a-real-one',
        ]);

        Cache::flush();
    }

    // ====================================================================
    // DEFAULT BINDINGS
    // ====================================================================

    #[Test]
    public function the_suite_runs_against_offline_providers(): void
    {
        // If this ever fails, the test suite has started making paid network
        // calls, and every other test in the project became non-deterministic.
        $this->assertInstanceOf(OfflineRoutingProvider::class, app(RoutingProvider::class));
        $this->assertInstanceOf(NullGeocodingProvider::class, app(GeocodingProvider::class));
        $this->assertInstanceOf(NullGeocodingProvider::class, app(PlacesProvider::class));
        $this->assertInstanceOf(PassthroughRoadsProvider::class, app(RoadsProvider::class));
    }

    #[Test]
    public function google_is_bound_only_when_enabled_and_keyed(): void
    {
        config(['services.google_maps.enabled' => true, 'services.google_maps.key' => '']);
        $this->refreshApplication();
        config(['services.google_maps.enabled' => true, 'services.google_maps.key' => '']);

        // Enabled but unkeyed is a misconfiguration, not a licence to call an
        // API with an empty key and log a wall of 403s.
        $this->assertInstanceOf(OfflineRoutingProvider::class, app(RoutingProvider::class));
    }

    // ====================================================================
    // OFFLINE ESTIMATES ARE HONEST
    // ====================================================================

    #[Test]
    public function an_offline_estimate_says_it_is_an_estimate(): void
    {
        $estimate = app(RoutingProvider::class)->route($this->origin(), $this->destination());

        // An ETA presented with false confidence is worse than one labelled a
        // guess, because somebody plans around it.
        $this->assertTrue($estimate->isEstimate);
        $this->assertSame('offline.haversine', $estimate->source);
    }

    #[Test]
    public function an_offline_estimate_is_still_usable(): void
    {
        $estimate = app(RoutingProvider::class)->route($this->origin(), $this->destination());

        $this->assertGreaterThan(0, $estimate->distanceMetres);
        $this->assertGreaterThan(0, $estimate->durationSeconds);
    }

    #[Test]
    public function offline_distance_exceeds_the_straight_line(): void
    {
        $estimate = app(RoutingProvider::class)->route($this->origin(), $this->destination());

        $straightLine = $this->origin()->metresTo($this->destination());

        // Roads are not straight. Being slightly pessimistic is survivable;
        // being optimistic leaves somebody believing they missed the bus.
        $this->assertGreaterThan($straightLine, $estimate->distanceMetres);
    }

    #[Test]
    public function a_matrix_answers_for_every_destination(): void
    {
        $destinations = [
            $this->destination(),
            LatLng::make(17.5000, 78.5000),
            LatLng::make(17.5200, 78.5300),
        ];

        $legs = app(RoutingProvider::class)->matrix($this->origin(), $destinations);

        $this->assertCount(3, $legs);
        // Further stops must not come out closer than nearer ones.
        $this->assertGreaterThan($legs[0]->distanceMetres, $legs[2]->distanceMetres);
    }

    #[Test]
    public function an_empty_matrix_is_not_an_error(): void
    {
        $this->assertSame([], app(RoutingProvider::class)->matrix($this->origin(), []));
    }

    // ====================================================================
    // GOOGLE, WHEN IT ANSWERS
    // ====================================================================

    #[Test]
    public function a_live_route_is_not_marked_as_an_estimate(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'distanceMeters' => 7400,
                'duration' => '960s',
                'polyline' => ['encodedPolyline' => 'abcd'],
            ]],
        ])]);

        $estimate = (new GoogleRoutingProvider)->route($this->origin(), $this->destination());

        $this->assertFalse($estimate->isEstimate);
        $this->assertSame(7400, $estimate->distanceMetres);
        $this->assertSame(960, $estimate->durationSeconds);
        $this->assertSame('abcd', $estimate->polyline);
    }

    #[Test]
    public function a_matrix_response_is_matched_by_index_not_by_order(): void
    {
        $this->enableGoogle();

        // Google does not guarantee the response arrives in request order.
        Http::fake(['routes.googleapis.com/*' => Http::response([
            ['originIndex' => 0, 'destinationIndex' => 1, 'distanceMeters' => 9000,
                'duration' => '900s', 'condition' => 'ROUTE_EXISTS'],
            ['originIndex' => 0, 'destinationIndex' => 0, 'distanceMeters' => 3000,
                'duration' => '300s', 'condition' => 'ROUTE_EXISTS'],
        ])]);

        $legs = (new GoogleRoutingProvider)->matrix($this->origin(), [
            $this->destination(),
            LatLng::make(17.5200, 78.5300),
        ]);

        $this->assertSame(3000, $legs[0]->distanceMetres);
        $this->assertSame(9000, $legs[1]->distanceMetres);
    }

    #[Test]
    public function an_unreachable_destination_keeps_its_offline_estimate(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response([
            ['originIndex' => 0, 'destinationIndex' => 0, 'condition' => 'ROUTE_NOT_FOUND'],
        ])]);

        $legs = (new GoogleRoutingProvider)->matrix($this->origin(), [$this->destination()]);

        // Reporting zero distance for an unreachable stop would put its ETA
        // at "now" and fire an arrival notification.
        $this->assertTrue($legs[0]->isEstimate);
        $this->assertGreaterThan(0, $legs[0]->distanceMetres);
    }

    // ====================================================================
    // GOOGLE, WHEN IT DOES NOT
    // ====================================================================

    #[Test]
    public function a_timeout_falls_back_instead_of_throwing(): void
    {
        $this->enableGoogle();

        Http::fake(fn () => throw new ConnectionException('timed out'));

        $estimate = (new GoogleRoutingProvider)->route($this->origin(), $this->destination());

        // Buses do not stop running when Google does.
        $this->assertTrue($estimate->isEstimate);
        $this->assertGreaterThan(0, $estimate->durationSeconds);
    }

    #[Test]
    public function a_server_error_falls_back(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response('upstream exploded', 500)]);

        $this->assertTrue(
            (new GoogleRoutingProvider)->route($this->origin(), $this->destination())->isEstimate,
        );
    }

    #[Test]
    public function an_error_reported_inside_a_200_is_still_an_error(): void
    {
        $this->enableGoogle();

        // Google reports its own failures with an HTTP 200 and a status field.
        Http::fake(['*' => Http::response(['status' => 'REQUEST_DENIED', 'results' => []])]);

        $this->assertNull((new GoogleGeocodingProvider)->geocode('Aditya University'));
    }

    #[Test]
    public function repeated_failures_open_the_circuit_breaker(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response('down', 500)]);

        $provider = new GoogleRoutingProvider;

        for ($i = 0; $i < 5; $i++) {
            $provider->route($this->origin(), LatLng::make(17.46 + ($i / 100), 78.46));
        }

        $this->assertFalse($provider->isAvailable());

        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [[
            'distanceMeters' => 100, 'duration' => '60s',
        ]]])]);

        // Still refusing to call: hammering a service that is already failing
        // makes an outage worse and costs money doing it.
        $this->assertTrue(
            $provider->route($this->origin(), LatLng::make(17.99, 78.99))->isEstimate,
        );
    }

    #[Test]
    public function the_daily_quota_stops_calls(): void
    {
        $this->enableGoogle();
        config(['services.google_maps.daily_limits.routing' => 2]);

        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [[
            'distanceMeters' => 500, 'duration' => '120s',
        ]]])]);

        $provider = new GoogleRoutingProvider;

        // Distinct destinations so the cache does not absorb them.
        $provider->route($this->origin(), LatLng::make(17.51, 78.51));
        $provider->route($this->origin(), LatLng::make(17.52, 78.52));

        $this->assertFalse($provider->isAvailable());

        // A budget that only counts successes is a budget that gets overrun.
        $this->assertTrue(
            $provider->route($this->origin(), LatLng::make(17.53, 78.53))->isEstimate,
        );
    }

    #[Test]
    public function a_repeated_route_is_served_from_cache(): void
    {
        $this->enableGoogle();

        Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [[
            'distanceMeters' => 4200, 'duration' => '600s',
        ]]])]);

        $provider = new GoogleRoutingProvider;

        $provider->route($this->origin(), $this->destination());
        $provider->route($this->origin(), $this->destination());

        // Two identical questions, one paid answer.
        Http::assertSentCount(1);
    }

    // ====================================================================
    // ROAD SNAPPING
    // ====================================================================

    #[Test]
    public function snapping_moves_a_drifting_point_onto_the_road(): void
    {
        $this->enableGoogle();

        Http::fake(['roads.googleapis.com/*' => Http::response([
            'snappedPoints' => [[
                'location' => ['latitude' => 17.4502, 'longitude' => 78.4501],
                'originalIndex' => 0,
            ]],
        ])]);

        $snapped = (new GoogleRoadsProvider)->snap($this->origin());

        $this->assertEqualsWithDelta(17.4502, $snapped->latitude, 0.00001);
    }

    #[Test]
    public function a_snap_that_moves_the_bus_too_far_is_rejected(): void
    {
        $this->enableGoogle();

        Http::fake(['roads.googleapis.com/*' => Http::response([
            'snappedPoints' => [[
                // Two kilometres away — the API has matched the wrong road.
                'location' => ['latitude' => 17.4700, 'longitude' => 78.4700],
                'originalIndex' => 0,
            ]],
        ])]);

        $snapped = (new GoogleRoadsProvider)->snap($this->origin());

        // A bus shown on a parallel carriageway is worse than one shown
        // slightly off the kerb.
        $this->assertEqualsWithDelta(self::COLLEGE[0], $snapped->latitude, 0.00001);
    }

    #[Test]
    public function snapping_returns_the_point_unchanged_when_unavailable(): void
    {
        $point = app(RoadsProvider::class)->snap($this->origin());

        // A pipeline that discarded readings it could not snap would go blind
        // exactly when the network is struggling.
        $this->assertSame(self::COLLEGE[0], $point->latitude);
        $this->assertSame(self::COLLEGE[1], $point->longitude);
    }

    #[Test]
    public function a_failed_snap_returns_every_point_unchanged(): void
    {
        $this->enableGoogle();

        Http::fake(['roads.googleapis.com/*' => Http::response('nope', 503)]);

        $points = [$this->origin(), $this->destination()];

        $this->assertEquals($points, (new GoogleRoadsProvider)->snapPath($points));
    }

    // ====================================================================
    // GEOCODING
    // ====================================================================

    #[Test]
    public function geocoding_returns_null_rather_than_guessing(): void
    {
        // Inventing coordinates for a stop would put children where the bus
        // does not go.
        $this->assertNull(app(GeocodingProvider::class)->geocode('Aditya University'));
    }

    #[Test]
    public function a_geocoded_address_is_cached_for_reuse(): void
    {
        $this->enableGoogle();

        Http::fake(['maps.googleapis.com/*' => Http::response([
            'status' => 'OK',
            'results' => [['geometry' => ['location' => ['lat' => 17.45, 'lng' => 78.45]]]],
        ])]);

        $provider = new GoogleGeocodingProvider;

        $provider->geocode('Aditya University, Surampalem');
        $provider->geocode('Aditya University, Surampalem');

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_empty_address_costs_nothing(): void
    {
        $this->enableGoogle();
        Http::fake();

        $this->assertNull((new GoogleGeocodingProvider)->geocode('   '));

        Http::assertNothingSent();
    }

    // ====================================================================
    // THE API SURFACE
    // ====================================================================

    #[Test]
    public function an_unmatched_address_is_a_200_not_a_404(): void
    {
        $admin = $this->createAdmin();

        // The request was fine; the answer is "no match".
        $this->getJson('/api/v1/geo/geocode?address=Nowhere%20At%20All', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.found', false)
            ->assertJsonPath('data.provider_available', false);
    }

    #[Test]
    public function a_short_place_query_is_refused_before_it_costs_anything(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/geo/places?query=ab', $this->authHeader($admin))
            ->assertStatus(422);
    }

    #[Test]
    public function the_status_endpoint_reports_degraded_operation(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/geo/status', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.routing.mode', 'offline_estimate')
            ->assertJsonPath('data.degraded', true);
    }

    #[Test]
    public function a_driver_cannot_spend_the_maps_budget(): void
    {
        $driver = $this->createDriver();

        $this->getJson('/api/v1/geo/geocode?address=Somewhere', $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function geo_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/geo/status')->assertStatus(401);
    }
}
