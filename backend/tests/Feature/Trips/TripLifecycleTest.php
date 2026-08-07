<?php

namespace Tests\Feature\Trips;

use App\Enums\BusStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\InspectionItem;
use App\Enums\TripStatus;
use App\Jobs\CloseOverdueTrips;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Services\Trips\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-06 — the trip lifecycle.
 *
 * Covers BR-250 to BR-253, BR-260 to BR-262, BR-267. The start gate is the
 * most safety-critical path in the product: it is the last automated check
 * before a bus carrying children leaves the depot.
 */
class TripLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A driver, their bus, and a trip they are assigned to, all in a state
     * where the trip could legally start.
     *
     * @return array{0: User, 1: Bus, 2: Trip}
     */
    private function readyToStart(): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->passInspection($bus, $driverUser);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
        ]);

        return [$driverUser, $bus->fresh(), $trip];
    }

    private function passInspection(Bus $bus, User $driverUser): void
    {
        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items,
            'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);
    }

    // ====================================================================
    // STARTING — BR-251, BR-252, BR-253
    // ====================================================================

    #[Test]
    public function the_assigned_driver_can_start_a_ready_trip(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.status', 'RUNNING');

        $trip->refresh();

        $this->assertSame(TripStatus::RUNNING, $trip->status);
        $this->assertNotNull($trip->actual_departure_time);
        $this->assertSame($driver->id, $trip->started_by_id);
    }

    #[Test]
    public function starting_a_trip_puts_the_bus_and_driver_on_the_road(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();

        $this->assertSame(BusStatus::RUNNING, $bus->fresh()->status);
        $this->assertSame(DriverStatus::ON_TRIP, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_trip_cannot_start_without_an_inspection(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
        ]);

        // BR-107 is the prerequisite BR-251 depends on.
        $response = $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertStatus(409);

        $this->assertStringContainsString('inspection', strtolower($response->json('message')));
        $this->assertSame(TripStatus::SCHEDULED, $trip->fresh()->status);
    }

    #[Test]
    public function a_trip_cannot_start_on_a_bus_with_an_expired_document(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::INSURANCE)->create();

        $this->passInspection($bus, $driverUser);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertStatus(409);
    }

    #[Test]
    public function a_trip_cannot_start_with_an_expired_licence(): void
    {
        $driverUser = $this->createDriver(profileAttributes: [
            'license_expiry_date' => now()->subDay()->toDateString(),
        ]);
        $bus = Bus::factory()->create();

        $this->passInspection($bus, $driverUser);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertStatus(409);
    }

    #[Test]
    public function a_trip_cannot_start_before_its_window_opens(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->passInspection($bus, $driverUser);

        // Departing in three hours; the window is 15 minutes.
        $trip = Trip::factory()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'trip_date' => now()->toDateString(),
            'scheduled_departure_time' => now()->addHours(3)->format('H:i:s'),
            'scheduled_arrival_time' => now()->addHours(4)->format('H:i:s'),
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertStatus(409);
    }

    #[Test]
    public function another_driver_cannot_start_someone_elses_trip(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();
        $intruder = $this->createDriver();

        // BR-253 — a valid token plus another trip's id must not work.
        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($intruder))
            ->assertStatus(403);

        $this->assertSame(TripStatus::SCHEDULED, $trip->fresh()->status);
    }

    #[Test]
    public function an_admin_can_start_a_trip_on_a_drivers_behalf(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();
        $admin = $this->createAdmin();

        // Operations covering for a failed driver device.
        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($admin))
            ->assertOk();
    }

    #[Test]
    public function a_student_cannot_start_a_trip(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();
        $student = $this->createStudent();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function starting_a_trip_requires_authentication(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start")->assertStatus(401);
    }

    #[Test]
    public function a_running_trip_cannot_be_started_again(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function starting_is_audited_with_the_delay(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();

        $log = AuditLog::where('action', 'TRIP_STARTED')->first();

        $this->assertNotNull($log);
        $this->assertArrayHasKey('delay_minutes', $log->new_values);
        $this->assertSame($driver->id, $log->user_id);
    }

    // ====================================================================
    // COMPLETING
    // ====================================================================

    #[Test]
    public function the_driver_can_complete_a_running_trip(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/complete", [], $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $trip->refresh();

        $this->assertNotNull($trip->actual_arrival_time);
        $this->assertFalse($trip->auto_closed);
    }

    #[Test]
    public function completing_returns_the_bus_and_driver_to_the_pool(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/complete", [], $this->authHeader($driver))->assertOk();

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
        $this->assertSame(DriverStatus::AVAILABLE, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_scheduled_trip_cannot_be_completed(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        // BR-250 — forward only, one step at a time.
        $this->postJson("/api/v1/trips/{$trip->id}/complete", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function a_completed_trip_cannot_be_reopened(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/complete", [], $this->authHeader($driver))->assertOk();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    // ====================================================================
    // AUTO-CLOSE — BR-260, BR-261
    // ====================================================================

    #[Test]
    public function an_overdue_running_trip_is_closed_automatically(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();

        // Past the arrival time plus the completion buffer.
        $this->travel(3)->hours();

        (new CloseOverdueTrips)->handle(app(TripService::class));

        $trip->refresh();

        $this->assertSame(TripStatus::COMPLETED, $trip->status);
        // BR-261 — distinguishable from a trip that closed properly.
        $this->assertTrue($trip->auto_closed);
        $this->assertNull($trip->ended_by_id);
    }

    #[Test]
    public function a_trip_still_within_its_window_is_not_auto_closed(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();

        (new CloseOverdueTrips)->handle(app(TripService::class));

        $this->assertSame(TripStatus::RUNNING, $trip->fresh()->status);
    }

    #[Test]
    public function auto_closed_trips_can_be_filtered_for_review(): void
    {
        $admin = $this->createAdmin();
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();
        $this->travel(3)->hours();
        (new CloseOverdueTrips)->handle(app(TripService::class));

        $this->getJson('/api/v1/trips?anomalous=1&date='.$trip->trip_date->toDateString(),
            $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    // ====================================================================
    // CANCELLING — BR-262
    // ====================================================================

    #[Test]
    public function an_admin_can_cancel_a_scheduled_trip(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/cancel", [
            'reason' => 'Flooding on the approach road to the campus gate.',
        ], $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $trip->refresh();

        $this->assertNotNull($trip->cancelled_at);
        $this->assertSame($admin->id, $trip->cancelled_by_id);
    }

    #[Test]
    public function cancelling_requires_a_reason(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/cancel", [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    #[Test]
    public function a_trivial_cancellation_reason_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();

        // The reason is sent to riders verbatim.
        $this->postJson("/api/v1/trips/{$trip->id}/cancel", ['reason' => 'n/a'],
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function cancelling_a_running_trip_releases_the_bus_and_driver(): void
    {
        $admin = $this->createAdmin();
        [$driver, $bus, $trip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))->assertOk();

        $this->postJson("/api/v1/trips/{$trip->id}/cancel", [
            'reason' => 'Mechanical fault reported en route by the driver.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
        $this->assertSame(DriverStatus::AVAILABLE, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_completed_trip_cannot_be_cancelled(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->completed()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/cancel", [
            'reason' => 'Trying to cancel something that already happened.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_cannot_cancel_their_own_trip(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();

        // Cancelling affects every rider on the route; it is an operations call.
        $this->postJson("/api/v1/trips/{$trip->id}/cancel", [
            'reason' => 'I would rather not drive today, thank you.',
        ], $this->authHeader($driver))->assertStatus(403);

        $this->assertSame(TripStatus::SCHEDULED, $trip->fresh()->status);
    }

    // ====================================================================
    // REASSIGNMENT — BR-267
    // ====================================================================

    #[Test]
    public function an_admin_can_reassign_the_bus(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();
        $replacement = Bus::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", [
            'bus_id' => $replacement->id,
            'reason' => 'Original bus withdrawn for service.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($replacement->id, $trip->fresh()->bus_id);
    }

    #[Test]
    public function an_admin_can_reassign_the_driver(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();
        $replacement = Driver::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", [
            'driver_id' => $replacement->id,
            'reason' => 'Original driver called in sick.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($replacement->id, $trip->fresh()->driver_id);
    }

    #[Test]
    public function a_bus_with_an_expired_document_cannot_be_assigned(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();
        $replacement = Bus::factory()->withExpiredDocument(DocumentType::PERMIT)->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", [
            'bus_id' => $replacement->id,
            'reason' => 'Substitution.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_with_an_expired_licence_cannot_be_assigned(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();
        $replacement = Driver::factory()->licenceExpired()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", [
            'driver_id' => $replacement->id,
            'reason' => 'Substitution.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_already_on_a_trip_cannot_be_assigned_another(): void
    {
        $admin = $this->createAdmin();
        [$busyDriver, $bus, $busyTrip] = $this->readyToStart();

        $this->postJson("/api/v1/trips/{$busyTrip->id}/start", [], $this->authHeader($busyDriver))
            ->assertOk();

        $otherTrip = Trip::factory()->create();

        $this->postJson("/api/v1/trips/{$otherTrip->id}/reassign", [
            'driver_id' => $busyDriver->driver->id,
            'reason' => 'Substitution.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function reassignment_requires_a_bus_or_a_driver(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", ['reason' => 'Nothing changed.'],
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function a_terminal_trip_cannot_be_reassigned(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->cancelled()->create();
        $replacement = Bus::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", [
            'bus_id' => $replacement->id,
            'reason' => 'Too late for this.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_cannot_reassign_a_trip(): void
    {
        [$driver, $bus, $trip] = $this->readyToStart();
        $replacement = Bus::factory()->create();

        $this->postJson("/api/v1/trips/{$trip->id}/reassign", [
            'bus_id' => $replacement->id,
            'reason' => 'I prefer the other bus.',
        ], $this->authHeader($driver))->assertStatus(403);
    }

    // ====================================================================
    // VISIBILITY
    // ====================================================================

    #[Test]
    public function a_driver_sees_only_their_own_trips(): void
    {
        $alice = $this->createDriver();
        $bob = $this->createDriver();

        Trip::factory()->create(['driver_id' => $alice->driver->id]);
        Trip::factory()->create(['driver_id' => $bob->driver->id]);

        $this->getJson('/api/v1/trips', $this->authHeader($alice))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function a_student_sees_only_trips_on_their_own_route(): void
    {
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $route->id])->save();

        Trip::factory()->create(['route_id' => $route->id]);
        Trip::factory()->create();

        $this->getJson('/api/v1/trips', $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function an_unassigned_student_sees_no_trips(): void
    {
        $student = $this->createStudent();
        Trip::factory()->count(3)->create();

        // Not every trip in the fleet.
        $this->getJson('/api/v1/trips', $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function an_admin_sees_every_trip(): void
    {
        $admin = $this->createAdmin();
        Trip::factory()->count(3)->create();

        $this->getJson('/api/v1/trips', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 3);
    }

    #[Test]
    public function a_driver_cannot_read_another_drivers_trip(): void
    {
        $alice = $this->createDriver();
        $bob = $this->createDriver();
        $bobsTrip = Trip::factory()->create(['driver_id' => $bob->driver->id]);

        $this->getJson("/api/v1/trips/{$bobsTrip->id}", $this->authHeader($alice))
            ->assertStatus(403);
    }

    #[Test]
    public function reading_an_unknown_trip_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/trips/019fd73c-0000-7000-8000-000000000000', $this->authHeader($admin))
            ->assertStatus(404);
    }
}
