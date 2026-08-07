<?php

namespace Tests\Feature\Incidents;

use App\Enums\BusStatus;
use App\Enums\InspectionItem;
use App\Enums\ReplacementStatus;
use App\Jobs\DetectStalledTrips;
use App\Models\Bus;
use App\Models\Notification;
use App\Models\ReplacementAssignment;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Services\Incidents\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-12 — replacement vehicles, and the trips that go quiet.
 *
 * A recommendation is not a dispatch. Everything here turns on that: the
 * system proposes, a human commits the money.
 */
class ReplacementAndStallTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Trip, 2: Bus} */
    private function runningTrip(int $capacity = 40): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity($capacity)->create();

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

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))->assertOk();

        // The schedule factory quietly creates a bus of its own. Park every
        // vehicle that isn't this trip's, so each test declares its own spare
        // pool and candidate ranking is not decided by a fixture.
        Bus::whereKeyNot($bus->getKey())
            ->update(['status' => BusStatus::OFFLINE->value]);

        return [$driverUser, $trip->fresh(), $bus->fresh()];
    }

    private function breakdown(User $driver, Trip $trip): VehicleIncident
    {
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'BREAKDOWN',
            'trip_id' => $trip->id,
            'description' => 'Engine has failed, the bus will not move.',
            'evidence_id' => $this->incidentEvidence($driver),
        ], $this->authHeader($driver))->assertStatus(201);

        return VehicleIncident::latest('created_at')->first();
    }

    // ====================================================================
    // RECOMMENDATION
    // ====================================================================

    #[Test]
    public function a_recommendation_is_not_a_dispatch(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        $spare = Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);

        $assignment = ReplacementAssignment::first();

        $this->assertSame(ReplacementStatus::RECOMMENDED, $assignment->status);
        // Nothing has moved and nobody has been told a bus is coming.
        $this->assertNull($assignment->dispatched_at);
        $this->assertSame(BusStatus::AVAILABLE, $spare->fresh()->status);
    }

    #[Test]
    public function a_replacement_too_small_to_seat_everyone_is_not_offered(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip(50);

        foreach (range(1, 30) as $ignored) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))->assertOk();
        }

        // The only spare available seats twenty. Ten people would be left at
        // the roadside.
        Bus::factory()->withCapacity(20)->create();

        $this->breakdown($driver, $trip);

        $assignment = ReplacementAssignment::first();

        // BR-360 — the request still stands, so operations can see that a
        // trip needs a vehicle and none is suitable. What they must not see
        // is a bus proposed that cannot do the job.
        $this->assertNotNull($assignment);
        $this->assertNull($assignment->replacement_bus_id);
        $this->assertSame(30, $assignment->passengers_to_transfer);
    }

    #[Test]
    public function an_unfulfillable_request_cannot_be_approved(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        // No spare of any kind exists.
        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))
            ->assertStatus(409);
    }

    #[Test]
    public function a_bus_with_lapsed_documents_is_never_recommended(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $expired = Bus::factory()->withCapacity(60)->create();
        $expired->documents()->make()->forceFill([
            'bus_id' => $expired->id,
            'document_type' => 'INSURANCE',
            'document_number' => 'INS-LAPSED-1',
            'issued_on' => now()->subYears(2),
            'expires_on' => now()->subDay(),
        ])->save();

        $this->breakdown($driver, $trip);

        // Sending an uninsured bus to a breakdown compounds the problem.
        $this->assertNull(ReplacementAssignment::first()->replacement_bus_id);
    }

    #[Test]
    public function a_bus_already_on_a_trip_is_never_recommended(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        [$otherDriver, $otherTrip, $busyBus] = $this->runningTrip(60);

        $this->breakdown($driver, $trip);

        $recommended = ReplacementAssignment::first()?->replacement_bus_id;

        $this->assertNotSame((string) $busyBus->id, (string) $recommended);
    }

    #[Test]
    public function the_nearest_suitable_bus_is_recommended_first(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        // Proximity is read from the driver sitting in the spare vehicle,
        // which is the only position the system actually knows.
        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => 17.4500, 'longitude' => 78.4500,
        ], $this->authHeader($driver))->assertOk();

        $far = Bus::factory()->withCapacity(50)->create();
        $this->createDriver()->driver->forceFill([
            'assigned_bus_id' => $far->id,
            'current_latitude' => 17.9000, 'current_longitude' => 78.9000,
        ])->save();

        $near = Bus::factory()->withCapacity(50)->create();
        $this->createDriver()->driver->forceFill([
            'assigned_bus_id' => $near->id,
            'current_latitude' => 17.4510, 'current_longitude' => 78.4510,
        ])->save();

        $this->breakdown($driver, $trip);

        $this->assertSame(
            (string) $near->id,
            (string) ReplacementAssignment::first()->replacement_bus_id,
        );
    }

    // ====================================================================
    // APPROVAL CHAIN
    // ====================================================================

    #[Test]
    public function proximity_ranking_works_from_real_reported_positions(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => 17.4500, 'longitude' => 78.4500,
        ], $this->authHeader($driver))->assertOk();

        // Two spare buses, each with a driver who is out on their own trip and
        // reporting position normally — no coordinates set by hand anywhere.
        [$farDriver, $farTrip, $farBus] = $this->runningTrip(50);
        $this->postJson("/api/v1/trips/{$farTrip->id}/positions", [
            'latitude' => 17.9000, 'longitude' => 78.9000,
        ], $this->authHeader($farDriver))->assertOk();

        [$nearDriver, $nearTrip, $nearBus] = $this->runningTrip(50);
        $this->postJson("/api/v1/trips/{$nearTrip->id}/positions", [
            'latitude' => 17.4510, 'longitude' => 78.4510,
        ], $this->authHeader($nearDriver))->assertOk();

        // Free both spares up so they are candidates.
        foreach ([[$farDriver, $farTrip], [$nearDriver, $nearTrip]] as [$d, $t]) {
            $this->postJson("/api/v1/trips/{$t->id}/complete", [
                'odometer_reading' => 10050,
            ], $this->authHeader($d))->assertOk();
        }

        $this->breakdown($driver, $trip);

        // The previous version of this test set driver coordinates directly,
        // which hid the fact that nothing in the running system ever wrote
        // them. Going through the GPS endpoint is what proves the ranking is
        // reachable in production rather than only in a fixture.
        $this->assertSame(
            (string) $nearBus->id,
            (string) ReplacementAssignment::first()->replacement_bus_id,
        );
    }

    #[Test]
    public function approval_records_who_committed_the_money(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))->assertOk();

        $assignment = ReplacementAssignment::find($id);

        $this->assertSame(ReplacementStatus::APPROVED, $assignment->status);
        $this->assertSame((string) $admin->id, (string) $assignment->approved_by_id);
        $this->assertNotNull($assignment->approved_at);
    }

    #[Test]
    public function operations_may_override_the_recommended_bus(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();
        $preferred = Bus::factory()->withCapacity(55)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        // The dispatcher knows things the ranking does not.
        $this->postJson("/api/v1/replacements/{$id}/approve", [
            'bus_id' => $preferred->id,
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(
            (string) $preferred->id,
            (string) ReplacementAssignment::find($id)->replacement_bus_id,
        );
    }

    #[Test]
    public function an_override_is_still_subject_to_capacity(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip(50);

        foreach (range(1, 30) as $ignored) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))->assertOk();
        }

        Bus::factory()->withCapacity(50)->create();
        $tooSmall = Bus::factory()->withCapacity(15)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [
            'bus_id' => $tooSmall->id,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function rejection_requires_a_reason(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/reject", [], $this->authHeader($admin))
            ->assertStatus(422);

        $this->postJson("/api/v1/replacements/{$id}/reject", [
            'reason' => 'No spare driver available at this hour.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(ReplacementStatus::REJECTED, ReplacementAssignment::find($id)->status);
    }

    #[Test]
    public function a_replacement_cannot_be_dispatched_before_it_is_approved(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/dispatch", [], $this->authHeader($admin))
            ->assertStatus(409);
    }

    #[Test]
    public function a_rejected_replacement_cannot_be_approved_afterwards(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/reject", [
            'reason' => 'Depot has no cover this morning.',
        ], $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))
            ->assertStatus(409);
    }

    // ====================================================================
    // DISPATCH AND ARRIVAL
    // ====================================================================

    #[Test]
    public function dispatch_tells_waiting_passengers_what_to_look_for(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        $spare = Bus::factory()->withCapacity(50)->create();

        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $trip->route_id])->save();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/dispatch", [], $this->authHeader($admin))->assertOk();

        $notification = Notification::where('user_id', $student->id)
            ->where('event_key', 'replacement.dispatched')->first();

        // A registration number is the only thing that makes it recognisable
        // at the roadside.
        $this->assertNotNull($notification);
        $this->assertStringContainsString($spare->fresh()->registration_number, $notification->body);
    }

    #[Test]
    public function arrival_moves_the_trip_onto_the_new_vehicle(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        $spare = Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/dispatch", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/arrived", [], $this->authHeader($admin))->assertOk();

        // The trip is the same trip. Only the metal underneath it changed.
        $this->assertSame((string) $spare->id, (string) $trip->fresh()->bus_id);
        $this->assertSame(ReplacementStatus::ARRIVED, ReplacementAssignment::find($id)->status);
    }

    #[Test]
    public function the_broken_bus_stays_out_of_service_after_the_handover(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/dispatch", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/arrived", [], $this->authHeader($admin))->assertOk();

        // Handing the passengers over does not repair the gearbox.
        $this->assertSame(BusStatus::BREAKDOWN, $bus->fresh()->status);
    }

    #[Test]
    public function the_boarding_record_survives_the_vehicle_change(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        foreach (range(1, 4) as $ignored) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))->assertOk();
        }

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/dispatch", [], $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/replacements/{$id}/arrived", [], $this->authHeader($admin))->assertOk();

        // Who is on board is a safety record, not a property of the bus.
        $this->assertSame(4, $trip->fresh()->occupied_seat_count);
    }

    #[Test]
    public function a_driver_cannot_approve_a_replacement(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();
        Bus::factory()->withCapacity(50)->create();

        $this->breakdown($driver, $trip);
        $id = ReplacementAssignment::first()->id;

        $this->postJson("/api/v1/replacements/{$id}/approve", [], $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_see_the_replacement_queue(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/replacements', $this->authHeader($student))->assertStatus(403);
    }

    // ====================================================================
    // BR-259 — STALLED TRIPS
    // ====================================================================

    #[Test]
    public function a_trip_that_stops_reporting_raises_an_incident(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => 17.4500, 'longitude' => 78.4500,
        ], $this->authHeader($driver))->assertOk();

        // Fifteen minutes of silence from a bus carrying children is not a
        // gap in the data, it is a question that needs answering.
        $this->travel(16)->minutes();

        (new DetectStalledTrips)->handle(app(IncidentService::class));

        $this->assertDatabaseHas('vehicle_incidents', [
            'trip_id' => $trip->id,
            'incident_type' => 'TRACKING_LOST',
        ]);
    }

    #[Test]
    public function a_trip_still_reporting_is_left_alone(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->travel(16)->minutes();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => 17.4500, 'longitude' => 78.4500,
        ], $this->authHeader($driver))->assertOk();

        (new DetectStalledTrips)->handle(app(IncidentService::class));

        $this->assertDatabaseCount('vehicle_incidents', 0);
    }

    #[Test]
    public function a_stalled_trip_is_reported_once_not_every_five_minutes(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => 17.4500, 'longitude' => 78.4500,
        ], $this->authHeader($driver))->assertOk();

        $this->travel(16)->minutes();

        $service = app(IncidentService::class);
        (new DetectStalledTrips)->handle($service);
        $this->travel(6)->minutes();
        (new DetectStalledTrips)->handle($service);

        // An alert that repeats every five minutes gets muted, and then the
        // real one gets muted with it.
        $this->assertDatabaseCount('vehicle_incidents', 1);
    }

    #[Test]
    public function a_driver_reporting_traffic_does_not_mask_a_silent_bus(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => 17.4500, 'longitude' => 78.4500,
        ], $this->authHeader($driver))->assertOk();

        // The driver says they are stuck in traffic — and then the phone dies.
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'CONGESTION',
            'trip_id' => $trip->id,
            'description' => 'Heavy traffic on the ring road.',
        ], $this->authHeader($driver))->assertStatus(201);

        $this->travel(16)->minutes();

        (new DetectStalledTrips)->handle(app(IncidentService::class));

        // If tracking loss shared a type with congestion, the open congestion
        // report would suppress this for the rest of the run.
        $this->assertDatabaseHas('vehicle_incidents', [
            'trip_id' => $trip->id,
            'incident_type' => 'TRACKING_LOST',
        ]);
    }

    #[Test]
    public function a_driver_cannot_claim_their_own_bus_went_silent(): void
    {
        [$driver, $trip, $bus] = $this->runningTrip();

        // Tracking loss is inferred from missing data. Letting it be asserted
        // would let a driver manufacture cover for an unexplained gap.
        $this->postJson('/api/v1/incidents', [
            'incident_type' => 'TRACKING_LOST',
            'trip_id' => $trip->id,
            'description' => 'My signal dropped out for a while.',
        ], $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['incident_type']]);
    }

    #[Test]
    public function a_silent_bus_stays_on_the_road(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->travel(16)->minutes();

        (new DetectStalledTrips)->handle(app(IncidentService::class));

        // Assert the incident exists first — the job logs and swallows its
        // own failures, so the two assertions below would otherwise pass on
        // an incident that was never created.
        $this->assertDatabaseCount('vehicle_incidents', 1);

        // Losing signal in a tunnel is not a fault. Grounding the bus and
        // raising a workshop ticket over it would make the alert useless.
        $this->assertSame(BusStatus::RUNNING, $bus->fresh()->status);
        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    #[Test]
    public function a_finished_trip_is_never_flagged_as_stalled(): void
    {
        $this->createAdmin();
        [$driver, $trip, $bus] = $this->runningTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10050,
        ], $this->authHeader($driver))->assertOk();

        $this->travel(60)->minutes();

        (new DetectStalledTrips)->handle(app(IncidentService::class));

        $this->assertDatabaseCount('vehicle_incidents', 0);
    }
}
