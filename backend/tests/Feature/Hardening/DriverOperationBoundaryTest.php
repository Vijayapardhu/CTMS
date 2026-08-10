<?php

namespace Tests\Feature\Hardening;

use App\Enums\AccessLevel;
use App\Enums\InspectionItem;
use App\Enums\TripStatus;
use App\Models\Bus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G3-3 — the driver-operation family.
 *
 * These endpoints carry no `RoleAuthorize`, because the driver app must reach
 * them from a bus. Their policies then asked `isAdmin()`, so every
 * administrator satisfied them — and an account created for read-only
 * oversight completed a running trip, raised incidents, annotated them, and
 * could record the inspection that clears a bus for service.
 *
 * The rule now, in every affected policy:
 *
 *     the driver doing their own job
 *     OR an administrator meeting the tier the operation actually costs
 *
 * and never "any administrator".
 *
 * Every mutation here asserts the **database**, not the status code. A 403 that
 * arrives after the write is not a refusal.
 */
class DriverOperationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 12.9716;

    private const LNG = 77.5946;

    /**
     * A running trip, started through the real flow by its own driver.
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

    // ====================================================================
    // THE DEFECT ITSELF
    // ====================================================================

    #[Test]
    public function a_viewer_cannot_complete_a_running_trip(): void
    {
        [, $trip] = $this->runningTrip();
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10100,
        ], $this->authHeader($viewer))->assertStatus(403);

        // The bus is still running. Before the fix this returned 200 and the
        // trip was COMPLETED, with `ended_by_id` set to the observer.
        $after = $trip->fresh();
        $this->assertSame(TripStatus::RUNNING, $after->status);
        $this->assertNull($after->ended_by_id);
        $this->assertNull($after->actual_arrival_time);
    }

    #[Test]
    public function a_supervisor_cannot_complete_a_running_trip_either(): void
    {
        [, $trip] = $this->runningTrip();
        $supervisor = $this->createAdminAt(AccessLevel::SUPPORT);

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10100,
        ], $this->authHeader($supervisor))->assertStatus(403);

        $this->assertSame(TripStatus::RUNNING, $trip->fresh()->status);
    }

    #[Test]
    public function operations_may_complete_a_trip_on_a_drivers_behalf(): void
    {
        [, $trip] = $this->runningTrip();
        $head = $this->createAdminAt(AccessLevel::OPERATIONS);

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10100,
        ], $this->authHeader($head))->assertOk();

        // The stand-in the docblock always described.
        $this->assertSame(TripStatus::COMPLETED, $trip->fresh()->status);
    }

    #[Test]
    public function a_super_admin_inherits_that_through_the_ladder(): void
    {
        [, $trip] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10100,
        ], $this->authHeader($this->createSuperAdmin()))->assertOk();

        $this->assertSame(TripStatus::COMPLETED, $trip->fresh()->status);
    }

    // ====================================================================
    // THE DRIVER STILL WORKS — THE WHOLE POINT
    // ====================================================================

    #[Test]
    public function the_assigned_driver_still_runs_their_own_trip(): void
    {
        [$driverUser, $trip] = $this->runningTrip();

        // Started already, above. Position, then completion.
        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => self::LAT, 'longitude' => self::LNG,
        ], $this->authHeader($driverUser))->assertSuccessful();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10100,
        ], $this->authHeader($driverUser))->assertOk();

        $this->assertSame(TripStatus::COMPLETED, $trip->fresh()->status);
    }

    #[Test]
    public function another_driver_still_cannot_touch_this_trip(): void
    {
        [, $trip] = $this->runningTrip();
        $stranger = $this->createDriver();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10100,
        ], $this->authHeader($stranger))->assertStatus(403);

        $this->assertSame(TripStatus::RUNNING, $trip->fresh()->status);
    }

    // ====================================================================
    // INCIDENTS — RAISING, ANNOTATING AND WITHDRAWING ARE SEPARATE
    // ====================================================================

    #[Test]
    public function a_viewer_cannot_raise_an_incident(): void
    {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'description' => 'Raised by read-only oversight.',
        ], $this->authHeader($viewer))->assertStatus(403);

        $this->assertDatabaseCount('vehicle_incidents', 0);
    }

    #[Test]
    public function a_supervisor_may_raise_an_incident(): void
    {
        $supervisor = $this->createAdminAt(AccessLevel::SUPPORT);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'description' => 'Reported to the office by telephone.',
        ], $this->authHeader($supervisor))->assertStatus(201);

        $this->assertDatabaseCount('vehicle_incidents', 1);
    }

    #[Test]
    public function a_driver_may_still_raise_one_from_the_roadside(): void
    {
        // A system that makes somebody wonder whether they may report an
        // emergency has already failed.
        $driver = $this->createDriver();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'description' => 'Emergency (SOS)',
        ], $this->authHeader($driver))->assertStatus(201);
    }

    #[Test]
    public function a_viewer_cannot_annotate_an_incident(): void
    {
        $driver = $this->createDriver();
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'description' => 'Emergency (SOS)',
        ], $this->authHeader($driver))->assertStatus(201);

        $incident = VehicleIncident::firstOrFail();
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        // The controller asked for `view` until now, so reading an incident
        // carried the right to write on it.
        $this->postJson("/api/v1/incidents/{$incident->id}/notes", [
            'note' => 'A note from read-only oversight.',
        ], $this->authHeader($viewer))->assertStatus(403);
    }

    #[Test]
    public function a_supervisor_may_annotate_an_incident(): void
    {
        $driver = $this->createDriver();
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'description' => 'Emergency (SOS)',
        ], $this->authHeader($driver))->assertStatus(201);

        $incident = VehicleIncident::firstOrFail();

        $this->postJson("/api/v1/incidents/{$incident->id}/notes", [
            'note' => 'Called the driver; he is safe.',
        ], $this->authHeader($this->createAdminAt(AccessLevel::SUPPORT)))
            ->assertStatus(201);
    }

    #[Test]
    public function the_reporter_may_still_annotate_and_withdraw_their_own(): void
    {
        $driver = $this->createDriver();
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'description' => 'Emergency (SOS)',
        ], $this->authHeader($driver))->assertStatus(201);

        $incident = VehicleIncident::firstOrFail();

        $this->postJson("/api/v1/incidents/{$incident->id}/notes", [
            'note' => 'Adding detail to my own report.',
        ], $this->authHeader($driver))->assertStatus(201);

        // Whether an SOS may be withdrawn at all is a business rule, not an
        // authorisation one. What matters here is that the reporter is not
        // refused by the policy.
        $this->assertNotSame(
            403,
            $this->postJson("/api/v1/incidents/{$incident->id}/cancel", [
                'reason' => 'Raised in error; the bus is fine.',
            ], $this->authHeader($driver))->status(),
        );
    }

    #[Test]
    public function a_viewer_cannot_withdraw_somebody_elses_alert(): void
    {
        $driver = $this->createDriver();
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'description' => 'Emergency (SOS)',
        ], $this->authHeader($driver))->assertStatus(201);

        $incident = VehicleIncident::firstOrFail();
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        $this->postJson("/api/v1/incidents/{$incident->id}/cancel", [
            'reason' => 'Withdrawn by read-only oversight.',
        ], $this->authHeader($viewer))->assertStatus(403);

        // Others may already have acted on it.
        $this->assertFalse((bool) $incident->fresh()->was_cancelled);
    }

    // ====================================================================
    // INSPECTIONS — RECORDING ONE CLEARS A BUS FOR SERVICE
    // ====================================================================

    #[Test]
    public function a_viewer_cannot_record_an_inspection(): void
    {
        $bus = Bus::factory()->create();
        $driverUser = $this->createDriver();
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'driver_id' => $driverUser->driver->id,
            'items' => $items,
            'odometer_reading' => 10000,
        ], $this->authHeader($viewer))->assertStatus(403);

        // Recording one clears a bus for service. Nothing was recorded.
        $this->assertDatabaseCount('vehicle_inspections', 0);
    }

    #[Test]
    public function operations_may_record_an_inspection_on_a_drivers_behalf(): void
    {
        $bus = Bus::factory()->create();
        $driverUser = $this->createDriver();

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'driver_id' => $driverUser->driver->id,
            'items' => $items,
            'odometer_reading' => 10000,
        ], $this->authHeader($this->createAdminAt(AccessLevel::OPERATIONS)))
            ->assertStatus(201);
    }

    // ====================================================================
    // EVIDENCE
    // ====================================================================

    #[Test]
    public function a_viewer_cannot_upload_evidence(): void
    {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        // A valid payload on purpose: the FormRequest validates before the
        // controller reaches its policy, so an empty body answers 422 and
        // never exercises the authorisation this test is about.
        $this->post('/api/v1/evidence', [
            'file' => UploadedFile::fake()->image('defect.jpg'),
            'category' => 'INSPECTION_PHOTO',
        ], $this->authHeader($viewer))->assertStatus(403);

        $this->assertDatabaseCount('evidence_files', 0);
    }

    #[Test]
    public function a_supervisor_reaches_the_evidence_upload(): void
    {
        $supervisor = $this->createAdminAt(AccessLevel::SUPPORT);

        $this->assertNotSame(
            403,
            $this->post('/api/v1/evidence', [
                'file' => UploadedFile::fake()->image('defect.jpg'),
                'category' => 'INSPECTION_PHOTO',
            ], $this->authHeader($supervisor))->status(),
        );
    }

    // ====================================================================
    // THE FAMILY, MECHANICALLY
    // ====================================================================

    #[Test]
    public function no_driver_operation_mutation_admits_a_viewer(): void
    {
        [, $trip] = $this->runningTrip();
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);
        $stopId = $trip->route->stops()->first()->id;

        // Every mutation on a trip that the driver app performs. A 403 is the
        // only acceptable answer for read-only oversight; anything else means
        // authorisation passed and only the payload or the state refused.
        // Payloads are valid on purpose. A FormRequest validates before the
        // controller reaches its policy, so an empty body answers 422 and
        // never exercises the authorisation this test exists to check.
        $operations = [
            ['POST', "/api/v1/trips/{$trip->id}/start", []],
            ['POST', "/api/v1/trips/{$trip->id}/complete", ['odometer_reading' => 10100]],
            ['POST', "/api/v1/trips/{$trip->id}/positions", ['latitude' => self::LAT, 'longitude' => self::LNG]],
            ['POST', "/api/v1/trips/{$trip->id}/board", ['route_stop_id' => $stopId]],
            ['POST', "/api/v1/trips/{$trip->id}/alight", ['route_stop_id' => $stopId]],
            ['POST', "/api/v1/trips/{$trip->id}/left-behind", ['route_stop_id' => $stopId, 'count' => 1]],
            ['POST', "/api/v1/trips/{$trip->id}/stops/{$stopId}/arrive", []],
            ['POST', "/api/v1/trips/{$trip->id}/stops/{$stopId}/skip", ['reason' => 'Road closed by police']],
        ];

        $admitted = [];

        foreach ($operations as [$method, $path, $payload]) {
            $status = $this->json($method, $path, $payload, $this->authHeader($viewer))->status();

            if ($status !== 403) {
                $admitted[] = "{$method} {$path} → {$status}";
            }
        }

        $this->assertSame([], $admitted,
            "These driver operations still admit a VIEWER:\n".implode("\n", $admitted));
    }
}
