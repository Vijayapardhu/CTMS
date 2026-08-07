<?php

namespace Tests\Feature\Incidents;

use App\Enums\BusStatus;
use App\Enums\IncidentClass;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\InspectionItem;
use App\Enums\NotificationPriority;
use App\Jobs\EscalateUnacknowledgedIncidents;
use App\Models\Bus;
use App\Models\EvidenceFile;
use App\Models\MaintenanceTicket;
use App\Models\Notification;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-11 — incident reporting and triage.
 *
 * Three classes, three sets of guarantees. The tests are grouped by class,
 * because that is the axis the behaviour actually varies on.
 */
class IncidentReportingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A driver on a running trip with passengers aboard.
     *
     * @return array{0: User, 1: Trip, 2: Bus}
     */
    private function runningTrip(): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertOk();

        return [$driverUser, $trip->fresh(), $bus->fresh()];
    }

    // ====================================================================
    // CLASS A — LIFE SAFETY
    // ====================================================================

    #[Test]
    public function a_driver_can_raise_an_sos_with_one_field(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        // Demanding a description from someone in an emergency is
        // indefensible. Type alone is enough to act on.
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        $incident = VehicleIncident::first();

        $this->assertSame(IncidentClass::LIFE_SAFETY, $incident->incident_class);
        $this->assertSame(IncidentStatus::REPORTED, $incident->status);
    }

    #[Test]
    public function an_sos_notifies_operations_critically(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        $notification = Notification::where('user_id', $admin->id)
            ->where('event_key', 'incident.sos.raised')->first();

        $this->assertNotNull($notification);
        // BR-353 — bypasses batching, quiet hours and preference.
        $this->assertSame(NotificationPriority::CRITICAL, $notification->priority);
    }

    #[Test]
    public function an_sos_takes_the_bus_out_of_service(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'ACCIDENT',
            'trip_id' => $trip->id,
            'description' => 'Collision at the junction.',
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertSame(BusStatus::BREAKDOWN, $bus->fresh()->status);
    }

    #[Test]
    public function a_medical_emergency_does_not_send_the_bus_to_the_workshop(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'MEDICAL',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        // A student fainting is an emergency, not a fault. Raising a workshop
        // ticket over it buries the workshop in work it cannot act on, and
        // — because an incident cannot close over an open ticket — keeps a
        // serviceable bus grounded until a mechanic signs it off.
        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    #[Test]
    public function a_collision_does_send_the_bus_to_the_workshop(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'ACCIDENT',
            'trip_id' => $trip->id,
            'description' => 'Struck a barrier at the junction.',
        ], $this->authHeader($driver))->assertStatus(201);

        // Here the vehicle is implicated, and must not carry anyone again
        // until it has been looked at.
        $this->assertDatabaseCount('maintenance_tickets', 1);
    }

    #[Test]
    public function an_sos_records_how_many_people_were_aboard(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        foreach (range(1, 3) as $ignored) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))
                ->assertOk();
        }

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        // The first thing a responder needs to know.
        $this->assertSame(3, VehicleIncident::first()->passengers_aboard);
    }

    #[Test]
    public function passengers_aboard_and_waiting_get_different_messages(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $aboard = $this->createStudent();
        $aboard->student->forceFill(['route_id' => $trip->route_id])->save();

        $waiting = $this->createStudent();
        $waiting->student->forceFill(['route_id' => $trip->route_id])->save();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [
            'student_id' => $aboard->student->id,
        ], $this->authHeader($driver))->assertOk();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed on the flyover.',
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))->assertStatus(201);

        // BR-365 — "stay on the bus" and "do not keep waiting" are opposite
        // instructions and must not be sent to the wrong person.
        $aboardMessage = Notification::where('user_id', $aboard->id)
            ->where('event_key', 'incident.reported.aboard')->first();
        $waitingMessage = Notification::where('user_id', $waiting->id)
            ->where('event_key', 'incident.reported.waiting')->first();

        $this->assertNotNull($aboardMessage);
        $this->assertNotNull($waitingMessage);
        $this->assertStringContainsString('Stay on the bus', $aboardMessage->body);
        $this->assertStringContainsString('replacement', $waitingMessage->body);
    }

    #[Test]
    public function an_unacknowledged_life_safety_incident_escalates(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'MEDICAL',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        // Two minutes of silence on a medical emergency is the whole failure.
        $this->travel(3)->minutes();

        (new EscalateUnacknowledgedIncidents)->handle(app(IncidentService::class));

        $this->assertSame(IncidentStatus::ESCALATED, VehicleIncident::first()->status);
        $this->assertSame(1, Notification::where('user_id', $admin->id)
            ->where('event_key', 'incident.escalated')->count());
    }

    #[Test]
    public function an_acknowledged_incident_does_not_escalate(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [], $this->authHeader($admin))
            ->assertOk();

        $this->travel(10)->minutes();

        (new EscalateUnacknowledgedIncidents)->handle(app(IncidentService::class));

        $this->assertSame(IncidentStatus::ACKNOWLEDGED, VehicleIncident::find($id)->status);
    }

    #[Test]
    public function escalation_happens_once_per_incident(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        $this->travel(3)->minutes();

        (new EscalateUnacknowledgedIncidents)->handle(app(IncidentService::class));
        (new EscalateUnacknowledgedIncidents)->handle(app(IncidentService::class));

        $this->assertSame(1, Notification::where('event_key', 'incident.escalated')->count());
    }

    #[Test]
    public function a_cancelled_sos_is_recorded_not_erased(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/cancel", [
            'note' => 'Pressed by accident in my pocket.',
        ], $this->authHeader($driver))->assertOk();

        $incident = VehicleIncident::find($id);

        // BR-355 — a false alarm is still a fact about what happened.
        $this->assertNotNull($incident);
        $this->assertTrue($incident->was_cancelled);
        $this->assertSame(IncidentStatus::RESOLVED, $incident->status);
    }

    #[Test]
    public function a_driver_cannot_cancel_someone_elses_incident(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();
        $other = $this->createDriver();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/cancel", ['note' => 'Not mine to cancel.'],
            $this->authHeader($other))->assertStatus(403);
    }

    // ====================================================================
    // CLASS B — OPERATIONAL
    // ====================================================================

    #[Test]
    public function a_breakdown_opens_a_maintenance_ticket(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Gearbox failure, cannot select a gear.',
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))->assertStatus(201);

        // BR-350 — without anyone having to remember to raise one.
        $this->assertDatabaseHas('maintenance_tickets', [
            'bus_id' => $bus->id,
            'status' => 'OPEN',
            'priority' => 'URGENT',
        ]);

        $this->assertNotNull(VehicleIncident::first()->maintenance_ticket_id);
    }

    #[Test]
    public function an_operational_incident_requires_a_photograph(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        // Evidence is what the workshop works from, and what justifies taking
        // a bus off the road.
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'FLAT_TYRE',
            'trip_id' => $trip->id,
            'description' => 'Nearside rear tyre is flat.',
        ], $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['evidence_id']]);
    }

    #[Test]
    public function an_operational_incident_requires_a_description(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'ENGINE_FAULT',
            'trip_id' => $trip->id,
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['description']]);
    }

    #[Test]
    public function a_breakdown_recommends_a_replacement(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        // A suitable spare exists.
        Bus::factory()->withCapacity(50)->create();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine failure on the bypass.',
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseHas('replacement_assignments', [
            'trip_id' => $trip->id,
            'status' => 'RECOMMENDED',
        ]);
    }

    #[Test]
    public function a_driver_reporting_the_vehicle_can_continue_keeps_it_in_service(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'FUEL',
            'trip_id' => $trip->id,
            'description' => 'Fuel gauge reading low, will refuel after the run.',
            'evidence_id' => $this->incidentEvidence($driver),
            'vehicle_can_continue' => true,
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertSame(BusStatus::RUNNING, $bus->fresh()->status);
    }

    // ====================================================================
    // CLASS C — SERVICE
    // ====================================================================

    #[Test]
    public function a_service_incident_does_not_take_the_bus_off_the_road(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION',
            'trip_id' => $trip->id,
            'description' => 'Heavy traffic on the ring road.',
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertSame(BusStatus::RUNNING, $bus->fresh()->status);
    }

    #[Test]
    public function a_service_incident_opens_no_maintenance_ticket(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'WEATHER',
            'trip_id' => $trip->id,
            'description' => 'Heavy rain, driving slowly.',
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    #[Test]
    public function a_service_incident_does_not_interrupt_passengers(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $trip->route_id])->save();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'DIVERSION',
            'trip_id' => $trip->id,
            'description' => 'Diverting around roadworks.',
        ], $this->authHeader($driver))->assertStatus(201);

        // The updated ETA they can already see is the right signal.
        $this->assertSame(0, Notification::where('user_id', $student->id)->count());
    }

    #[Test]
    public function a_service_incident_never_escalates(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION',
            'trip_id' => $trip->id,
            'description' => 'Stuck in traffic.',
        ], $this->authHeader($driver))->assertStatus(201);

        $this->travel(2)->hours();

        (new EscalateUnacknowledgedIncidents)->handle(app(IncidentService::class));

        $this->assertSame(IncidentStatus::REPORTED, VehicleIncident::first()->status);
    }

    // ====================================================================
    // IMMUTABILITY AND LIFECYCLE — BR-357
    // ====================================================================

    #[Test]
    public function follow_up_is_appended_as_notes(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/notes", [
            'note' => 'Ambulance arrived on scene at 08:15.',
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseCount('incident_notes', 1);
        // The original report is untouched.
        $this->assertSame('Emergency (SOS)', VehicleIncident::find($id)->description);
    }

    #[Test]
    public function acknowledging_is_distinct_from_resolving(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [], $this->authHeader($admin))
            ->assertOk();

        $incident = VehicleIncident::find($id);

        // "Someone has seen this" is not "this is dealt with".
        $this->assertSame(IncidentStatus::ACKNOWLEDGED, $incident->status);
        $this->assertNotNull($incident->acknowledged_at);
        $this->assertNull($incident->resolved_at);
    }

    #[Test]
    public function acknowledging_twice_is_idempotent(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [], $this->authHeader($admin))->assertOk();
        $first = VehicleIncident::find($id)->acknowledged_at;

        $this->travel(1)->minutes();
        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [], $this->authHeader($admin))->assertOk();

        $this->assertEquals($first, VehicleIncident::find($id)->acknowledged_at);
    }

    #[Test]
    public function resolving_requires_an_account_of_what_happened(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/resolve", [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution_notes']]);
    }

    #[Test]
    public function an_incident_cannot_be_closed_while_its_ticket_is_open(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Gearbox failure.',
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/resolve", [
            'resolution_notes' => 'Bus recovered to the depot by the workshop.',
        ], $this->authHeader($admin))->assertOk();

        // A bus must not return to service on an administrative tidy-up.
        $this->postJson("/api/v1/incidents/{$id}/close", [], $this->authHeader($admin))
            ->assertStatus(409);
    }

    #[Test]
    public function an_incident_closes_once_its_ticket_is_signed_off(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Gearbox failure.',
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))->json('data.id');

        $ticket = MaintenanceTicket::first();

        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/start", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/maintenance-tickets/{$ticket->id}/complete", [
            'resolution_notes' => 'Gearbox rebuilt and road tested.',
        ], $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/incidents/{$id}/resolve", [
            'resolution_notes' => 'Bus recovered and repaired.',
        ], $this->authHeader($admin))->assertOk();

        // The other half of the BR-358 guard. Blocking on an open ticket is
        // only correct if a completed one actually unblocks — otherwise no
        // vehicle incident can ever be closed.
        $this->postJson("/api/v1/incidents/{$id}/close", [], $this->authHeader($admin))
            ->assertOk();
    }

    #[Test]
    public function a_closed_incident_cannot_be_reopened(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION',
            'trip_id' => $trip->id,
            'description' => 'Traffic.',
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/resolve", [
            'resolution_notes' => 'Traffic cleared, trip continued normally.',
        ], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/incidents/{$id}/close", [], $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/incidents/{$id}/resolve", [
            'resolution_notes' => 'Trying to resolve it again.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // OFFLINE AND AUTHORIZATION
    // ====================================================================

    #[Test]
    public function a_replayed_offline_report_is_absorbed(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $payload = [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
            'idempotency_key' => 'offline-sos-1',
        ];

        $this->postJson('/api/v1/incidents', $payload, $this->authHeader($driver))->assertStatus(201);
        $this->postJson('/api/v1/incidents', $payload, $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseCount('vehicle_incidents', 1);
    }

    #[Test]
    public function an_offline_report_keeps_its_original_timestamp(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $happenedAt = now()->subMinutes(20);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
            'reported_at' => $happenedAt->toIso8601String(),
        ], $this->authHeader($driver))->assertStatus(201);

        // Otherwise every delayed SOS looks like it happened when the signal
        // came back, and the response-time record is fiction.
        $this->assertTrue(
            VehicleIncident::first()->reported_at->isBefore(now()->subMinutes(15)),
        );
    }

    #[Test]
    public function a_student_cannot_report_an_incident(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();
        $student = $this->createStudent();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function reporting_requires_authentication(): void
    {
        $this->postJson('/api/v1/incidents', ['incident_type' => 'SOS'])->assertStatus(401);
    }

    #[Test]
    public function a_driver_cannot_triage_an_incident(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [], $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_sees_only_their_own_reports(): void
    {
        [$alice, $tripA, $busA] = $this->runningTrip();
        [$bob, $tripB, $busB] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $tripB->id,
        ], $this->authHeader($bob))->assertStatus(201);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $tripA->id,
        ], $this->authHeader($alice))->assertStatus(201);

        // Both halves matter: Alice sees her own report, and only her own.
        // Asserting the zero alone would also pass on a broken listing.
        $this->getJson('/api/v1/incidents', $this->authHeader($alice))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/v1/incidents', $this->authHeader($bob))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function a_driver_cannot_read_another_drivers_incident(): void
    {
        [$alice, $tripA, $busA] = $this->runningTrip();
        [$bob, $tripB, $busB] = $this->runningTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $tripB->id,
        ], $this->authHeader($bob))->json('data.id');

        $this->getJson("/api/v1/incidents/{$id}", $this->authHeader($alice))->assertStatus(403);
    }

    #[Test]
    public function life_safety_incidents_sort_above_everything_else(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION', 'trip_id' => $trip->id,
            'description' => 'Traffic on the bypass.',
        ], $this->authHeader($driver))->assertStatus(201);

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))->assertStatus(201);

        // An unresolved SOS must never be on page two.
        $response = $this->getJson('/api/v1/incidents', $this->authHeader($admin))->assertOk();

        $this->assertSame('LIFE_SAFETY', $response->json('data.0.incident_class'));
    }

    #[Test]
    public function the_incident_type_catalogue_is_served_to_the_client(): void
    {
        $driver = $this->createDriver();

        $response = $this->getJson('/api/v1/incidents/types', $this->authHeader($driver))->assertOk();

        $this->assertCount(count(IncidentType::reportableCases()), $response->json('data'));
        $this->assertNotContains('TRACKING_LOST', array_column($response->json('data'), 'value'));
        $this->assertArrayHasKey('requires_photo', $response->json('data.0'));
        $this->assertArrayHasKey('class', $response->json('data.0'));
    }

    #[Test]
    public function an_unknown_incident_type_is_rejected(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'ALIEN_ABDUCTION', 'trip_id' => $trip->id,
        ], $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['incident_type']]);
    }

    #[Test]
    public function the_stored_path_never_leaves_the_api(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        $evidenceId = $this->incidentEvidence($driver);

        $response = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine failure.',
            'evidence_id' => $evidenceId,
        ], $this->authHeader($driver))->assertStatus(201);

        $evidence = EvidenceFile::find($evidenceId);

        // A real file exists on a private disk — otherwise the assertions
        // below prove nothing. The old version of this test asserted against a
        // string the client had invented.
        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->isAttached());
        $this->assertNotSame('', $evidence->path);

        // BR-367 — the path is the one field that would turn an id into a URL
        // somebody fetches without a check.
        foreach ([
            $response->getContent(),
            $this->getJson('/api/v1/incidents', $this->authHeader($driver))->getContent(),
            $this->getJson("/api/v1/incidents/{$response->json('data.id')}", $this->authHeader($driver))->getContent(),
        ] as $body) {
            $this->assertStringNotContainsString($evidence->path, $body);
            $this->assertStringNotContainsString('"disk"', $body);
        }
    }
}
