<?php

namespace Tests\Feature\Hardening;

use App\Enums\DriverStatus;
use App\Enums\InspectionItem;
use App\Models\Bus;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-cutting rules that belong to no single module.
 *
 * BR-010, BR-054, BR-109, BR-303, BR-511 — each one is the sort of rule that
 * gets written into a design document and then never enforced anywhere,
 * because it is nobody's module.
 */
class CrossCuttingRuleTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // BR-010 — ADMINISTRATORS MUST REMAIN
    // ====================================================================

    #[Test]
    public function the_last_administrators_cannot_be_deactivated(): void
    {
        $first = $this->createSuperAdmin();
        $second = $this->createSuperAdmin();

        // Two remain; taking one out would leave one, below the floor of two.
        $this->patchJson("/api/v1/users/{$second->id}/status", ['is_active' => false],
            $this->authHeader($first))
            ->assertStatus(409)
            ->assertJsonPath('errors.minimum_active_admins', 2);

        $this->assertTrue($second->fresh()->is_active);
    }

    #[Test]
    public function an_administrator_can_be_deactivated_while_enough_remain(): void
    {
        $first = $this->createSuperAdmin();
        $second = $this->createSuperAdmin();
        $third = $this->createSuperAdmin();

        $this->patchJson("/api/v1/users/{$third->id}/status", ['is_active' => false],
            $this->authHeader($first))->assertOk();

        $this->assertFalse($third->fresh()->is_active);
    }

    #[Test]
    public function deactivating_a_driver_is_never_blocked_by_the_admin_floor(): void
    {
        $admin = $this->createSuperAdmin();
        $this->createSuperAdmin();
        $driver = $this->createDriver();

        // The floor is about administrators, not headcount.
        $this->patchJson("/api/v1/users/{$driver->id}/status", ['is_active' => false],
            $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function the_system_identity_does_not_count_towards_the_floor(): void
    {
        $first = $this->createSuperAdmin();
        $second = $this->createSuperAdmin();

        // The scheduler's identity is an ADMIN row, but it is inactive and it
        // is not a person anyone can call at midnight. Counting it would let
        // the fleet be locked out with one real administrator left.
        $this->assertFalse(User::systemActor()->is_active);

        $this->patchJson("/api/v1/users/{$second->id}/status", ['is_active' => false],
            $this->authHeader($first))->assertStatus(409);
    }

    // ====================================================================
    // BR-054 — CAPACITY CANNOT SHRINK BELOW WHAT IS BOOKED
    // ====================================================================

    #[Test]
    public function a_bus_cannot_be_shrunk_below_its_booked_seats(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withCapacity(50)->create();

        Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'route_id' => Route::factory()->withStops()->create()->id,
            'booked_seat_count' => 40,
        ]);

        // Shrinking to 20 would silently strand twenty students who already
        // have a seat on this run.
        $this->putJson("/api/v1/buses/{$bus->id}", ['seating_capacity' => 20],
            $this->authHeader($admin))->assertStatus(409);

        $this->assertSame(50, $bus->fresh()->seating_capacity);
    }

    #[Test]
    public function a_bus_can_grow_at_any_time(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withCapacity(40)->create();

        Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'route_id' => Route::factory()->withStops()->create()->id,
            'booked_seat_count' => 35,
        ]);

        $this->putJson("/api/v1/buses/{$bus->id}", ['seating_capacity' => 55],
            $this->authHeader($admin))->assertOk();
    }

    // ====================================================================
    // BR-109 — A DRIVER STOOD DOWN AFTER A CRITICAL INCIDENT
    // ====================================================================

    /**
     * @return array{0: User, 1: Trip, 2: Bus}
     */
    private function runningTrip(): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))->assertOk();

        return [$driverUser, $trip->fresh(), $bus->fresh()];
    }

    #[Test]
    public function a_driver_who_reports_a_critical_incident_is_stood_down(): void
    {
        $this->createAdmin();
        [$driver, $trip] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'ACCIDENT',
            'trip_id' => $trip->id,
            'description' => 'Collision at the junction.',
        ], $this->authHeader($driver))->assertStatus(201);

        // Not a judgement about them: somebody who has just had an accident is
        // not in a state to take another bus out, and a short-staffed morning
        // should not get to make that call.
        $this->assertSame(DriverStatus::OFF_DUTY, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_stood_down_driver_cannot_start_another_trip(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'MEDICAL',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        $nextBus = Bus::factory()->withCapacity(40)->create();
        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$nextBus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 20000,
        ], $this->authHeader($driver))->assertStatus(201);

        $next = Trip::factory()->departingNow()->create([
            'bus_id' => $nextBus->id,
            'driver_id' => $driver->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        $this->postJson("/api/v1/trips/{$next->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function a_service_incident_does_not_stand_a_driver_down(): void
    {
        $this->createAdmin();
        [$driver, $trip] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION',
            'trip_id' => $trip->id,
            'description' => 'Heavy traffic on the ring road.',
        ], $this->authHeader($driver))->assertStatus(201);

        // Reporting traffic must not cost a driver their shift, or they will
        // stop reporting traffic.
        $this->assertSame(DriverStatus::ON_TRIP, $driver->driver->fresh()->status);
    }

    #[Test]
    public function standing_a_driver_down_is_audited(): void
    {
        $this->createAdmin();
        [$driver, $trip] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DRIVER_STOOD_DOWN_PENDING_REVIEW',
            'record_id' => $driver->driver->id,
        ]);
    }

    // ====================================================================
    // BR-303 — GPS INGEST IS RATE-LIMITED
    // ====================================================================

    #[Test]
    public function position_reporting_is_rate_limited(): void
    {
        [$driver, $trip] = $this->runningTrip();

        $limit = (int) config('ctms.rate_limit.gps_per_minute', 120);

        // A device stuck in a loop, or a token being replayed, must not be
        // able to fill the trace table faster than a bus can move.
        for ($i = 0; $i <= $limit; $i++) {
            $response = $this->postJson("/api/v1/trips/{$trip->id}/positions", [
                'latitude' => 17.4500 + ($i / 100000),
                'longitude' => 78.4500,
            ], $this->authHeader($driver));

            if ($response->status() === 429) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("GPS ingest accepted more than {$limit} readings in a minute without throttling.");
    }

    // ====================================================================
    // BR-511 — ERRORS NEVER EXPOSE INTERNALS
    // ====================================================================

    #[Test]
    public function an_unexpected_failure_reveals_nothing_about_the_internals(): void
    {
        config(['app.debug' => false]);

        $admin = $this->createAdmin();

        // Break a table the endpoint depends on, then call it.
        Schema::drop('buses');

        $response = $this->getJson('/api/v1/buses', $this->authHeader($admin));

        $response->assertStatus(500);

        $body = $response->getContent();

        foreach (['SQLSTATE', 'select *', 'vendor\\laravel', '/app/', 'Illuminate\\Database'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "The error response leaked '{$leak}'.");
        }

        $response->assertJsonPath('success', false);
    }

    #[Test]
    public function a_missing_endpoint_does_not_describe_the_router(): void
    {
        $body = $this->getJson('/api/v1/there-is-no-such-thing')->assertStatus(404)->getContent();

        $this->assertStringNotContainsString('Illuminate', $body);
        $this->assertStringNotContainsString('Symfony', $body);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('gps');

        parent::tearDown();
    }
}
