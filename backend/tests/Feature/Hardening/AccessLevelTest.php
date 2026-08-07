<?php

namespace Tests\Feature\Hardening;

use App\Enums\AccessLevel;
use App\Enums\InspectionItem;
use App\Enums\MaintenancePriority;
use App\Models\Bus;
use App\Models\MaintenanceTicket;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The second axis of authorization.
 *
 * `admins.access_level` existed from the first migration. It was stored,
 * accepted at registration, and returned to the client — and read by nothing.
 * Every policy asked only `isAdmin()`, so an account created as VIEWER could
 * approve replacement buses, close incidents, publish to the whole fleet and
 * export a student's file. The API told the client they were a viewer and then
 * let them do everything.
 *
 * The ladder is: VIEWER reads; SUPPORT runs the day; OPERATIONS commits money
 * and moves vehicles; SUPER_ADMIN owns accounts and the audit trail. A
 * supervisor is SUPPORT and a transport head is OPERATIONS or above — neither
 * needed a new role or a new endpoint.
 */
class AccessLevelTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // THE LADDER ITSELF
    // ====================================================================

    #[Test]
    public function the_tiers_rank_in_order(): void
    {
        $this->assertTrue(AccessLevel::SUPER_ADMIN->atLeast(AccessLevel::OPERATIONS));
        $this->assertTrue(AccessLevel::OPERATIONS->atLeast(AccessLevel::SUPPORT));
        $this->assertTrue(AccessLevel::SUPPORT->atLeast(AccessLevel::VIEWER));

        $this->assertFalse(AccessLevel::VIEWER->atLeast(AccessLevel::SUPPORT));
        $this->assertFalse(AccessLevel::SUPPORT->atLeast(AccessLevel::OPERATIONS));
        $this->assertFalse(AccessLevel::OPERATIONS->atLeast(AccessLevel::SUPER_ADMIN));
    }

    #[Test]
    public function a_non_admin_holds_no_tier(): void
    {
        // A driver is not a low-privilege administrator; they are not on this
        // ladder at all.
        $this->assertNull($this->createDriver()->accessLevel());
        $this->assertNull($this->createStudent()->accessLevel());
    }

    #[Test]
    public function an_admin_with_no_profile_row_holds_no_tier(): void
    {
        $orphan = User::factory()->admin()->create();

        // Defaulting a broken account to VIEWER would silently grant it read
        // access to everything a viewer can see.
        $this->assertNull($orphan->accessLevel());
        $this->assertFalse($orphan->hasAccessLevel(AccessLevel::VIEWER));
    }

    // ====================================================================
    // VIEWER — READ-ONLY OVERSIGHT
    // ====================================================================

    #[Test]
    public function a_viewer_can_read_the_fleet(): void
    {
        $viewer = $this->createViewer();

        $this->getJson('/api/v1/buses', $this->authHeader($viewer))->assertOk();
        $this->getJson('/api/v1/trips', $this->authHeader($viewer))->assertOk();
        $this->getJson('/api/v1/maintenance-tickets', $this->authHeader($viewer))->assertOk();
    }

    #[Test]
    public function a_viewer_cannot_change_the_fleet(): void
    {
        $viewer = $this->createViewer();
        $bus = Bus::factory()->create();

        // This is the account that could do everything before the tier was
        // enforced.
        $this->putJson("/api/v1/buses/{$bus->id}", ['seating_capacity' => 60],
            $this->authHeader($viewer))->assertStatus(403);

        $this->postJson('/api/v1/buses', [
            'registration_number' => 'KA-01-AA-1111',
            'seating_capacity' => 40,
        ], $this->authHeader($viewer))->assertStatus(403);
    }

    #[Test]
    public function a_viewer_cannot_raise_maintenance(): void
    {
        $viewer = $this->createViewer();

        $this->postJson('/api/v1/maintenance-tickets', [
            'bus_id' => Bus::factory()->create()->id,
            'issue_description' => 'A viewer should not be able to open this.',
        ], $this->authHeader($viewer))->assertStatus(403);
    }

    #[Test]
    public function the_refusal_names_the_tier_required(): void
    {
        $viewer = $this->createViewer();

        // A refusal a supervisor cannot act on is one they will work around.
        $this->postJson('/api/v1/maintenance-tickets', [
            'bus_id' => Bus::factory()->create()->id,
            'issue_description' => 'Checking the message.',
        ], $this->authHeader($viewer))
            ->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'SUPPORT')
                && str_contains($m, 'VIEWER'));
    }

    // ====================================================================
    // SUPPORT — THE SUPERVISOR'S DAY
    // ====================================================================

    /**
     * @return array{0: User, 1: Trip}
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

        return [$driverUser, $trip->fresh()];
    }

    #[Test]
    public function a_supervisor_can_answer_an_incident(): void
    {
        $supervisor = $this->createSupervisor();
        [$driver, $trip] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        // Answering a report at six in the morning is exactly what a
        // supervisor is for.
        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [],
            $this->authHeader($supervisor))->assertOk();
    }

    #[Test]
    public function a_supervisor_cannot_close_an_incident(): void
    {
        $supervisor = $this->createSupervisor();
        $operations = $this->createAdmin();
        [$driver, $trip] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION', 'trip_id' => $trip->id,
            'description' => 'Heavy traffic on the ring road.',
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/resolve", [
            'resolution_notes' => 'Traffic cleared, trip continued.',
        ], $this->authHeader($supervisor))->assertOk();

        // Closing is the act that lets a bus back on the road (BR-358).
        $this->postJson("/api/v1/incidents/{$id}/close", [],
            $this->authHeader($supervisor))->assertStatus(403);

        $this->postJson("/api/v1/incidents/{$id}/close", [],
            $this->authHeader($operations))->assertOk();
    }

    #[Test]
    public function a_supervisor_cannot_sign_off_maintenance(): void
    {
        $supervisor = $this->createSupervisor();
        $bus = Bus::factory()->create();

        $ticket = app(MaintenanceService::class)->open($bus, [
            'issue_description' => 'Brake fault.',
            'priority' => MaintenancePriority::URGENT,
        ], $supervisor);

        // Booking the work in is theirs.
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($supervisor))->assertOk();

        // Returning the vehicle to service is not.
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Looks fine to me.',
        ], $this->authHeader($supervisor))->assertStatus(403);

        $this->assertTrue(MaintenanceTicket::find($ticket->id)->isOpen());
    }

    #[Test]
    public function a_supervisor_cannot_change_the_network(): void
    {
        $supervisor = $this->createSupervisor();
        $route = Route::factory()->withStops()->create();

        $this->putJson("/api/v1/routes/{$route->id}", ['route_name' => 'Renamed'],
            $this->authHeader($supervisor))->assertStatus(403);
    }

    // ====================================================================
    // OPERATIONS — THE TRANSPORT HEAD'S DAY
    // ====================================================================

    #[Test]
    public function operations_can_change_the_fleet_and_the_network(): void
    {
        $operations = $this->createAdmin();
        $bus = Bus::factory()->create();
        $route = Route::factory()->withStops()->create();

        $this->putJson("/api/v1/buses/{$bus->id}", ['seating_capacity' => 60],
            $this->authHeader($operations))->assertOk();

        $this->putJson("/api/v1/routes/{$route->id}", ['route_name' => 'Renamed'],
            $this->authHeader($operations))->assertOk();
    }

    #[Test]
    public function operations_cannot_reach_the_audit_trail(): void
    {
        $operations = $this->createAdmin();

        // Who looked at whose data is a governance question, not an
        // operational one — and operations is the group most likely to appear
        // in the answer.
        $this->getJson('/api/v1/audit-logs', $this->authHeader($operations))->assertStatus(403);
        $this->getJson('/api/v1/data-access-logs', $this->authHeader($operations))->assertStatus(403);
        $this->getJson('/api/v1/retention-runs', $this->authHeader($operations))->assertStatus(403);
    }

    #[Test]
    public function operations_cannot_create_accounts_or_change_privilege(): void
    {
        $operations = $this->createAdmin();
        $target = $this->createDriver();

        $this->postJson('/api/v1/users', [
            'email' => 'new.admin@example.com',
            'phone_number' => '+911234567890',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'first_name' => 'New', 'last_name' => 'Admin',
            'role' => 'ADMIN', 'designation' => 'Head', 'department' => 'Transport',
            'access_level' => 'SUPER_ADMIN',
        ], $this->authHeader($operations))->assertStatus(403);

        $this->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false],
            $this->authHeader($operations))->assertStatus(403);
    }

    #[Test]
    public function operations_cannot_export_a_students_file(): void
    {
        $operations = $this->createAdmin();
        $student = $this->createStudent();

        $this->postJson("/api/v1/users/{$student->id}/subject-access-export", [
            'reason' => 'Operations should not be able to take this copy.',
        ], $this->authHeader($operations))->assertStatus(403);
    }

    // ====================================================================
    // SUPER_ADMIN
    // ====================================================================

    #[Test]
    public function a_super_admin_reaches_everything_below_it(): void
    {
        $super = $this->createSuperAdmin();
        $bus = Bus::factory()->create();

        // The ladder is cumulative: holding the top tier grants the ones under
        // it, so a transport head does not need a second account to do a
        // supervisor's job.
        $this->getJson('/api/v1/audit-logs', $this->authHeader($super))->assertOk();
        $this->putJson("/api/v1/buses/{$bus->id}", ['seating_capacity' => 55],
            $this->authHeader($super))->assertOk();
        $this->postJson('/api/v1/maintenance-tickets', [
            'bus_id' => $bus->id,
            'issue_description' => 'Raised by the transport head directly.',
        ], $this->authHeader($super))->assertStatus(201);
    }

    // ====================================================================
    // THE GATE ITSELF
    // ====================================================================

    #[Test]
    public function a_driver_is_refused_before_the_tier_is_even_considered(): void
    {
        $driver = $this->createDriver();

        // The role gate runs first, so a driver never reaches the tier check —
        // and a driver with no admin row must not fall through it.
        $this->getJson('/api/v1/audit-logs', $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function an_unauthenticated_request_is_401_not_403(): void
    {
        // The client should authenticate rather than give up.
        $this->getJson('/api/v1/audit-logs')->assertStatus(401);
    }

    #[Test]
    public function the_tier_is_reported_to_the_client(): void
    {
        $supervisor = $this->createSupervisor();

        $response = $this->getJson('/api/v1/auth/me', $this->authHeader($supervisor))->assertOk();

        // The client builds its navigation from this. Before enforcement it
        // was advertised and meaningless; it now matches what the API allows.
        $this->assertSame('SUPPORT', $response->json('data.profile.access_level'));
    }
}
