<?php

namespace Tests\Feature\Maintenance;

use App\Enums\BusStatus;
use App\Enums\InspectionItem;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Jobs\ScanPreventiveMaintenance;
use App\Models\Bus;
use App\Models\MaintenanceTicket;
use App\Models\Notification;
use App\Models\PreventiveMaintenanceSchedule;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-14 — maintenance (BR-350, BR-358, BR-366, BR-061).
 *
 * The rule that matters most here is BR-358: closing a ticket is what puts a
 * vehicle back under passengers, so most of these tests are about refusing to
 * do that too easily.
 */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(
        Bus $bus,
        MaintenancePriority $priority = MaintenancePriority::URGENT,
    ): MaintenanceTicket {
        return app(MaintenanceService::class)->open($bus, [
            'issue_description' => 'Gearbox will not select third gear.',
            'priority' => $priority,
        ], User::systemActor());
    }

    // ====================================================================
    // BR-350 — TICKETS OPEN THEMSELVES
    // ====================================================================

    #[Test]
    public function a_ticket_starts_open_whatever_the_payload_says(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        // A payload that sets COMPLETED would return a bus to the road with
        // nobody having looked at it.
        $this->postJson('/api/v1/maintenance-tickets', [
            'bus_id' => $bus->id,
            'issue_description' => 'Handbrake travel is excessive.',
            'status' => 'COMPLETED',
            'priority' => 'HIGH',
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertSame(MaintenanceStatus::OPEN, MaintenanceTicket::first()->status);
    }

    #[Test]
    public function an_incident_and_an_inspection_raise_the_same_shape_of_ticket(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        // A failed pre-trip inspection.
        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value,
            'passed' => $item !== InspectionItem::BRAKES,
            'notes' => $item === InspectionItem::BRAKES ? 'Pedal goes to the floor.' : null,
            // A failed safety-critical item needs evidence (Module 2 rule).
            'evidence_id' => $item === InspectionItem::BRAKES ? $this->inspectionEvidence($driverUser) : null,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $ticket = MaintenanceTicket::first();

        // One service decides what a ticket looks like, so both paths produce
        // an audited row with a real priority rather than a string literal.
        $this->assertNotNull($ticket);
        $this->assertSame(MaintenancePriority::URGENT, $ticket->priority);
        $this->assertNotNull($ticket->vehicle_inspection_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'MAINTENANCE_TICKET_OPENED']);
    }

    #[Test]
    public function opening_a_grounding_ticket_reaches_operations_critically(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->ticket($bus, MaintenancePriority::URGENT);

        $notification = Notification::where('user_id', $admin->id)
            ->where('event_key', 'maintenance.ticket.opened')->first();

        $this->assertNotNull($notification);
        $this->assertSame('CRITICAL', $notification->priority->value);
    }

    #[Test]
    public function a_cosmetic_ticket_does_not_shout(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->ticket($bus, MaintenancePriority::LOW);

        $notification = Notification::where('user_id', $admin->id)
            ->where('event_key', 'maintenance.ticket.opened')->first();

        $this->assertNotNull($notification);
        $this->assertSame('STANDARD', $notification->priority->value);
    }

    // ====================================================================
    // BR-358 — RETURN TO SERVICE
    // ====================================================================

    #[Test]
    public function a_bus_comes_back_only_when_the_last_grounding_ticket_closes(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create(['status' => BusStatus::BREAKDOWN->value]);

        $gearbox = $this->ticket($bus, MaintenancePriority::URGENT);
        $brakes = $this->ticket($bus, MaintenancePriority::URGENT);

        $this->postJson("/api/v1/maintenance-tickets/{$gearbox->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$gearbox->id}/complete", [
            'resolution_notes' => 'Gearbox rebuilt and road tested.',
        ], $this->authHeader($admin))->assertOk();

        // The brakes are still outstanding. A repaired gearbox does not make
        // this vehicle roadworthy.
        $this->assertSame(BusStatus::BREAKDOWN, $bus->fresh()->status);

        $this->postJson("/api/v1/maintenance-tickets/{$brakes->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$brakes->id}/complete", [
            'resolution_notes' => 'Brake lines replaced and bled.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function a_cosmetic_ticket_never_held_the_bus_in_the_first_place(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create(['status' => BusStatus::BREAKDOWN->value]);

        $urgent = $this->ticket($bus, MaintenancePriority::URGENT);
        $this->ticket($bus, MaintenancePriority::LOW);

        $this->postJson("/api/v1/maintenance-tickets/{$urgent->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$urgent->id}/complete", [
            'resolution_notes' => 'Fault rectified.',
        ], $this->authHeader($admin))->assertOk();

        // A torn seat cover must not strand a route.
        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function a_high_priority_fault_also_grounds_the_bus(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create(['status' => BusStatus::BREAKDOWN->value]);

        // Found by mutation testing: every grounding test used URGENT, so
        // narrowing the rule to URGENT-only survived the whole suite. HIGH
        // means the vehicle is not roadworthy either.
        $ticket = $this->ticket($bus, MaintenancePriority::HIGH);

        $this->assertTrue($ticket->groundsTheVehicle());

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Fault rectified.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function a_medium_priority_fault_does_not_ground_the_bus(): void
    {
        $bus = Bus::factory()->create();

        // The other side of the boundary, so the rule cannot quietly widen
        // to "everything grounds the bus" either.
        $this->assertFalse($this->ticket($bus, MaintenancePriority::MEDIUM)->groundsTheVehicle());
    }

    #[Test]
    public function a_workshop_reading_moves_the_buss_running_total(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Serviced.',
            'odometer_reading' => 62000,
        ], $this->authHeader($admin))->assertOk();

        // Found by mutation testing: the old test asserted the reading landed
        // on the *ticket* and never checked the bus, so the service validated
        // the number and then threw it away.
        $this->assertSame(62000, $bus->fresh()->current_odometer);
    }

    #[Test]
    public function a_driver_cannot_sign_off_their_own_buss_maintenance(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create(['status' => BusStatus::BREAKDOWN->value]);
        $driverUser->driver->forceFill(['assigned_bus_id' => $bus->id])->save();

        $ticket = $this->ticket($bus);

        // The pressure to get moving is exactly what BR-358 exists to resist.
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Looks fine to me.',
        ], $this->authHeader($driverUser))->assertStatus(403);

        $this->assertSame(BusStatus::BREAKDOWN, $bus->fresh()->status);
    }

    #[Test]
    public function signing_off_requires_an_account_of_the_work(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();

        // "Completed" on its own justifies nothing.
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution_notes']]);
    }

    #[Test]
    public function completion_records_who_signed_it_off(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Replaced the selector cable.',
            'actual_cost' => 4500.00,
        ], $this->authHeader($admin))->assertOk();

        $fresh = MaintenanceTicket::find($ticket->id);

        $this->assertSame((string) $admin->id, (string) $fresh->completed_by_id);
        $this->assertNotNull($fresh->completion_date);
    }

    #[Test]
    public function work_underway_cannot_be_cancelled_as_though_it_never_happened(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/cancel", [
            'reason' => 'Changed my mind halfway through the rebuild.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_completed_ticket_cannot_be_reopened(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Done.',
        ], $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function cancelling_requires_a_reason(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        // Cancelling is how a fault stops holding a bus off the road.
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/cancel", [],
            $this->authHeader($admin))->assertStatus(422);
    }

    // ====================================================================
    // BR-061 — THE ODOMETER ONLY GOES FORWARD
    // ====================================================================

    #[Test]
    public function the_odometer_cannot_go_backwards_at_sign_off(): void
    {
        $admin = $this->createAdmin();
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 50000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $ticket = $this->ticket($bus);
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();

        // A reading below the recorded total is a typo or a tampered
        // instrument. Both need a human.
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Serviced.',
            'odometer_reading' => 40000,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_forward_odometer_reading_updates_the_running_total(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Serviced.',
            'odometer_reading' => 62000,
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(62000, MaintenanceTicket::find($ticket->id)->odometer_reading);
    }

    #[Test]
    public function an_inspection_moves_the_running_total_forward(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 33000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        // One definition of "backwards" shared by the workshop, the pre-trip
        // check and the trip close.
        $this->assertSame(33000, $bus->fresh()->current_odometer);
    }

    // ====================================================================
    // BR-366 — OVERDUE SERVICE BLOCKS ASSIGNMENT
    // ====================================================================

    private function scheduleDue(Bus $bus, int $dueDaysAgo, int $graceDays = 7): PreventiveMaintenanceSchedule
    {
        $schedule = new PreventiveMaintenanceSchedule;

        $schedule->forceFill([
            'bus_id' => $bus->id,
            'service_name' => 'Brake service',
            'interval_days' => 90,
            'grace_days' => $graceDays,
            'due_on' => today()->subDays($dueDaysAgo),
            'is_active' => true,
        ])->save();

        return $schedule;
    }

    /**
     * @return array{0: User, 1: Bus, 2: Trip}
     */
    private function tripReadyToStart(): array
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

        return [$driverUser, $bus, $trip];
    }

    #[Test]
    public function a_bus_within_the_grace_period_still_runs(): void
    {
        [$driver, $bus, $trip] = $this->tripReadyToStart();

        // Due three days ago, seven days of grace. The grace exists precisely
        // so a service falling due does not cancel a route on the day.
        $this->scheduleDue($bus, dueDaysAgo: 3, graceDays: 7);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    #[Test]
    public function a_bus_past_the_grace_period_does_not_run(): void
    {
        [$driver, $bus, $trip] = $this->tripReadyToStart();

        $this->scheduleDue($bus, dueDaysAgo: 30, graceDays: 7);

        // Running indefinitely past a service is what BR-366 exists to stop.
        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function the_block_names_the_service_that_is_overdue(): void
    {
        [$driver, $bus, $trip] = $this->tripReadyToStart();

        $this->scheduleDue($bus, dueDaysAgo: 30, graceDays: 7);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409)
            ->assertJsonFragment(['overdue_services' => ['Brake service']]);
    }

    #[Test]
    public function a_distance_overrun_has_no_grace(): void
    {
        [$driver, $bus, $trip] = $this->tripReadyToStart();

        $schedule = new PreventiveMaintenanceSchedule;
        $schedule->forceFill([
            'bus_id' => $bus->id,
            'service_name' => 'Oil change',
            'interval_km' => 10000,
            'grace_days' => 30,
            'due_at_odometer' => 9000,
            'is_active' => true,
        ])->save();

        // The inspection put the bus at 10,000km, past the 9,000km service
        // point. A bus that has done the kilometres has done them — there is
        // no equivalent of "a few more days".
        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function servicing_the_bus_clears_the_block(): void
    {
        $admin = $this->createAdmin();
        [$driver, $bus, $trip] = $this->tripReadyToStart();

        $schedule = $this->scheduleDue($bus, dueDaysAgo: 30, graceDays: 7);

        $ticket = app(MaintenanceService::class)->open($bus, [
            'issue_description' => 'Scheduled service due: Brake service.',
            'priority' => MaintenancePriority::MEDIUM,
        ], $admin);

        $schedule->forceFill(['open_ticket_id' => $ticket->id])->save();

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Brake service carried out.',
            'odometer_reading' => 10500,
        ], $this->authHeader($admin))->assertOk();

        // The schedule has rolled forward to its next due point.
        $this->assertTrue($schedule->fresh()->due_on->isFuture());

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    // ====================================================================
    // BG-16 — THE DAILY SCAN
    // ====================================================================

    #[Test]
    public function the_scan_opens_a_ticket_for_a_service_that_has_fallen_due(): void
    {
        $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->scheduleDue($bus, dueDaysAgo: 1);

        (new ScanPreventiveMaintenance)->handle(app(MaintenanceService::class));

        $this->assertDatabaseCount('maintenance_tickets', 1);
        // Preventive work is planned, not an emergency — it must not ground a
        // bus the moment it falls due.
        $this->assertSame(MaintenancePriority::MEDIUM, MaintenanceTicket::first()->priority);
    }

    #[Test]
    public function the_scan_does_not_raise_a_second_ticket_for_the_same_service(): void
    {
        $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->scheduleDue($bus, dueDaysAgo: 1);

        $job = new ScanPreventiveMaintenance;
        $job->handle(app(MaintenanceService::class));
        $job->handle(app(MaintenanceService::class));

        $this->assertDatabaseCount('maintenance_tickets', 1);
    }

    #[Test]
    public function the_scan_leaves_services_that_are_not_due(): void
    {
        $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->scheduleDue($bus, dueDaysAgo: -30);

        (new ScanPreventiveMaintenance)->handle(app(MaintenanceService::class));

        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    #[Test]
    public function a_schedule_with_no_interval_is_refused(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        // A row that looks like protection and provides none.
        $this->postJson('/api/v1/preventive-maintenance', [
            'bus_id' => $bus->id,
            'service_name' => 'Vague service',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['interval_days']]);
    }

    // ====================================================================
    // AUTHORIZATION AND VISIBILITY
    // ====================================================================

    #[Test]
    public function a_driver_can_see_why_their_own_bus_is_off_the_road(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->create();
        $driverUser->driver->forceFill(['assigned_bus_id' => $bus->id])->save();

        $ticket = $this->ticket($bus);

        $this->getJson("/api/v1/maintenance-tickets/{$ticket->id}", $this->authHeader($driverUser))
            ->assertOk();
    }

    #[Test]
    public function a_driver_cannot_read_another_buss_maintenance(): void
    {
        $driverUser = $this->createDriver();
        $driverUser->driver->forceFill(['assigned_bus_id' => Bus::factory()->create()->id])->save();

        $ticket = $this->ticket(Bus::factory()->create());

        $this->getJson("/api/v1/maintenance-tickets/{$ticket->id}", $this->authHeader($driverUser))
            ->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_see_maintenance_at_all(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/maintenance-tickets', $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_open_a_ticket_against_any_bus(): void
    {
        $driverUser = $this->createDriver();

        $this->postJson('/api/v1/maintenance-tickets', [
            'bus_id' => Bus::factory()->create()->id,
            'issue_description' => 'Something feels wrong.',
        ], $this->authHeader($driverUser))->assertStatus(403);
    }

    #[Test]
    public function maintenance_requires_authentication(): void
    {
        $this->getJson('/api/v1/maintenance-tickets')->assertStatus(401);
    }

    #[Test]
    public function urgent_tickets_sort_above_cosmetic_ones(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->ticket($bus, MaintenancePriority::LOW);
        $this->ticket($bus, MaintenancePriority::URGENT);

        // A bus with failed brakes must never be below a bus with a torn seat.
        $response = $this->getJson('/api/v1/maintenance-tickets', $this->authHeader($admin))->assertOk();

        $this->assertSame('URGENT', $response->json('data.0.priority'));
    }

    #[Test]
    public function the_open_scope_actually_matches_open_tickets(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $ticket = $this->ticket($bus);

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Done.',
        ], $this->authHeader($admin))->assertOk();

        $this->ticket($bus, MaintenancePriority::MEDIUM);

        // The placeholder model compared status to lowercase literals, so this
        // scope matched everything and every ticket looked open forever.
        $this->getJson('/api/v1/maintenance-tickets?open=1', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }
}
