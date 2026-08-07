<?php

namespace Tests\Feature\Trips;

use App\Enums\ConsolidationStatus;
use App\Enums\InspectionItem;
use App\Enums\TripStatus;
use App\Jobs\ProposeConsolidations;
use App\Models\Bus;
use App\Models\Notification;
use App\Models\Route;
use App\Models\Trip;
use App\Models\TripConsolidation;
use App\Models\User;
use App\Services\Trips\ConsolidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-13 — smart consolidation (BR-361..BR-364).
 *
 * Merging two services saves fuel and strands people. Every test here is about
 * the second half of that sentence.
 */
class ConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_LAT = 17.4500;

    private const BASE_LNG = 78.4500;

    /**
     * A route whose stops start at the shared origin and then fan out, so two
     * routes built this way overlap at the start and diverge later.
     */
    private function routeWithStops(float $lngOffset = 0.0, int $stops = 4): Route
    {
        $route = Route::factory()->create();

        for ($i = 1; $i <= $stops; $i++) {
            // The first two stops are common ground; after that each route
            // pulls away by its own offset.
            $offset = $i <= 2 ? 0.0 : $lngOffset * $i;

            $route->stops()->make()->forceFill([
                'route_id' => $route->id,
                'stop_name' => "Stop {$i}",
                'sequence_number' => $i,
                'latitude' => self::BASE_LAT + ($i * 0.005),
                'longitude' => self::BASE_LNG + $offset,
                'address' => "Address {$i}",
                'distance_from_start_km' => $i * 2,
                'estimated_arrival_minutes' => $i * 6,
                'waiting_time_minutes' => 2,
                'stop_type' => 'BOTH',
            ])->save();
        }

        return $route->fresh('stops');
    }

    /**
     * A running trip with a driver, a bus of the given capacity, and the given
     * number of people aboard.
     *
     * @return array{0: User, 1: Trip}
     */
    private function runningTrip(Route $route, int $capacity, int $aboard = 0): array
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
            'route_id' => $route->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))->assertOk();

        for ($i = 0; $i < $aboard; $i++) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driverUser))->assertOk();
        }

        return [$driverUser, $trip->fresh()];
    }

    /**
     * A proposal in whatever state the test needs, on two overlapping routes.
     *
     * @return array{0: User, 1: TripConsolidation, 2: Trip, 3: Trip}
     */
    private function proposal(int $sourceAboard = 5, int $targetAboard = 5, int $targetCapacity = 50): array
    {
        $admin = $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, $sourceAboard);
        [, $target] = $this->runningTrip($this->routeWithStops(-0.01), $targetCapacity, $targetAboard);

        $consolidation = app(ConsolidationService::class)->propose($source, $target, $admin);

        return [$admin, $consolidation, $source->fresh(), $target->fresh()];
    }

    // ====================================================================
    // PROPOSAL
    // ====================================================================

    #[Test]
    public function a_proposal_changes_nothing_on_its_own(): void
    {
        [, $consolidation, $source, $target] = $this->proposal();

        $this->assertSame(ConsolidationStatus::PROPOSED, $consolidation->status);
        // Nobody's journey has changed yet.
        $this->assertSame(TripStatus::RUNNING, $source->status);
        $this->assertSame(TripStatus::RUNNING, $target->status);
        $this->assertNull($consolidation->executed_at);
    }

    #[Test]
    public function a_proposal_captures_the_figures_it_was_justified_by(): void
    {
        [, $consolidation] = $this->proposal(sourceAboard: 7, targetAboard: 4);

        // A decision reviewed in six months must be readable against what was
        // true when it was made, not what is true then.
        $this->assertSame(7, $consolidation->source_passengers);
        $this->assertSame(4, $consolidation->target_passengers);
        $this->assertSame(50, $consolidation->target_capacity);
    }

    #[Test]
    public function passengers_are_not_told_about_a_mere_proposal(): void
    {
        $student = $this->createStudent();

        [, $consolidation, $source] = $this->proposal();

        $student->student->forceFill(['route_id' => $source->route_id])->save();

        // Warning people their bus might be cancelled — when it probably will
        // not be — is its own harm.
        $this->assertSame(0, Notification::where('user_id', $student->id)
            ->where('event_key', 'consolidation.passengers_notified')->count());
    }

    #[Test]
    public function operations_is_told_a_decision_is_waiting(): void
    {
        $admin = $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, 5);
        [, $target] = $this->runningTrip($this->routeWithStops(-0.01), 50, 5);

        app(ConsolidationService::class)->propose($source, $target, $admin);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'event_key' => 'consolidation.proposed',
        ]);
    }

    #[Test]
    public function routes_that_never_meet_cannot_be_merged(): void
    {
        $admin = $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, 5);

        // A route on the other side of the city.
        $distant = Route::factory()->create();
        $distant->stops()->make()->forceFill([
            'route_id' => $distant->id, 'stop_name' => 'Far', 'sequence_number' => 1,
            'latitude' => 12.9716, 'longitude' => 77.5946, 'address' => 'Far away',
            'distance_from_start_km' => 1, 'estimated_arrival_minutes' => 5,
            'waiting_time_minutes' => 2, 'stop_type' => 'BOTH',
        ])->save();

        [, $target] = $this->runningTrip($distant->fresh('stops'), 50, 5);

        $this->postJson('/api/v1/consolidations', [
            'source_trip_id' => $source->id,
            'target_trip_id' => $target->id,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_trip_cannot_be_merged_into_itself(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->runningTrip($this->routeWithStops(0.01), 40, 5);

        $this->postJson('/api/v1/consolidations', [
            'source_trip_id' => $trip->id,
            'target_trip_id' => $trip->id,
        ], $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function a_trip_cannot_carry_two_open_proposals(): void
    {
        [$admin, , $source, $target] = $this->proposal();

        $this->postJson('/api/v1/consolidations', [
            'source_trip_id' => $source->id,
            'target_trip_id' => $target->id,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // BR-362 — EVERYONE HAS TO FIT
    // ====================================================================

    #[Test]
    public function a_merge_that_would_not_fit_is_refused_at_proposal(): void
    {
        $admin = $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, 25);
        [, $target] = $this->runningTrip($this->routeWithStops(-0.01), 30, 20);

        // 45 people, 30 seats.
        $this->postJson('/api/v1/consolidations', [
            'source_trip_id' => $source->id,
            'target_trip_id' => $target->id,
        ], $this->authHeader($admin))
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function a_merge_that_exactly_fills_the_bus_is_allowed(): void
    {
        $admin = $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, 18);
        [, $target] = $this->runningTrip($this->routeWithStops(-0.01), 30, 12);

        // Exactly 30 into 30 seats. Found by mutation testing: every capacity
        // test used a wide margin, so loosening the check by one passenger
        // survived the entire suite.
        $this->postJson('/api/v1/consolidations', [
            'source_trip_id' => $source->id,
            'target_trip_id' => $target->id,
        ], $this->authHeader($admin))->assertStatus(201);
    }

    #[Test]
    public function a_merge_one_passenger_over_capacity_is_refused(): void
    {
        $admin = $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, 19);
        [, $target] = $this->runningTrip($this->routeWithStops(-0.01), 30, 12);

        // Thirty-one people, thirty seats. Somebody stands, or somebody is
        // left at the kerb.
        $this->postJson('/api/v1/consolidations', [
            'source_trip_id' => $source->id,
            'target_trip_id' => $target->id,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_merge_that_stops_fitting_is_refused_at_approval(): void
    {
        [$admin, $consolidation, $source, $target] = $this->proposal(
            sourceAboard: 20, targetAboard: 20, targetCapacity: 45,
        );

        // Twelve more people board the target while the proposal sits in the
        // queue. The figures it was approved against no longer hold.
        $driver = $target->driver->user;

        foreach (range(1, 12) as $ignored) {
            $this->postJson("/api/v1/trips/{$target->id}/board", [], $this->authHeader($driver))->assertOk();
        }

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // BR-361 — MANAGER APPROVAL
    // ====================================================================

    #[Test]
    public function a_driver_cannot_approve_a_merge(): void
    {
        [, $consolidation, $source] = $this->proposal();

        $driver = $source->driver->user;

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_see_the_proposal_queue(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/consolidations', $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function proposals_require_authentication(): void
    {
        $this->getJson('/api/v1/consolidations')->assertStatus(401);
    }

    #[Test]
    public function approval_records_who_decided(): void
    {
        [$admin, $consolidation] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();

        $fresh = TripConsolidation::find($consolidation->id);

        $this->assertSame(ConsolidationStatus::APPROVED, $fresh->status);
        $this->assertSame((string) $admin->id, (string) $fresh->decided_by_id);
        $this->assertNotNull($fresh->decided_at);
    }

    #[Test]
    public function rejection_requires_a_reason(): void
    {
        [$admin, $consolidation] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/reject", [],
            $this->authHeader($admin))->assertStatus(422);

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/reject", [
            'reason' => 'Both services are needed for an exam finishing late.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(
            ConsolidationStatus::REJECTED,
            TripConsolidation::find($consolidation->id)->status,
        );
    }

    #[Test]
    public function a_rejected_proposal_cannot_be_executed(): void
    {
        [$admin, $consolidation] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/reject", [
            'reason' => 'Not this morning.',
        ], $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/execute", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // BR-363 — TOLD BEFORE, NOT AFTER
    // ====================================================================

    #[Test]
    public function a_merge_cannot_execute_before_the_passengers_are_told(): void
    {
        [$admin, $consolidation] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();

        // Being told your bus was cancelled after it was cancelled is not a
        // notification, it is an apology.
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/execute", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function the_notification_names_the_bus_to_look_for(): void
    {
        [$admin, $consolidation, $source, $target] = $this->proposal();

        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $source->route_id])->save();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertOk();

        $notification = Notification::where('user_id', $student->id)
            ->where('event_key', 'consolidation.passengers_notified')->first();

        $this->assertNotNull($notification);
        // A registration number is the only thing that makes a different bus
        // recognisable at the kerb.
        $this->assertStringContainsString(
            $target->bus->registration_number,
            $notification->body,
        );
    }

    #[Test]
    public function passengers_are_not_told_before_a_manager_has_decided(): void
    {
        [$admin, $consolidation] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function notifying_twice_does_not_message_people_twice(): void
    {
        [$admin, $consolidation, $source] = $this->proposal();

        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $source->route_id])->save();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'consolidation.passengers_notified')->count());
    }

    // ====================================================================
    // BR-364 — THE DIVERGENCE POINT
    // ====================================================================

    #[Test]
    public function the_divergence_point_is_recorded_at_proposal(): void
    {
        [, $consolidation] = $this->proposal();

        // The last stop the two routes have in common — stop 2, after which
        // they pull apart.
        $this->assertNotNull($consolidation->divergence_stop_id);
        $this->assertSame(2, $consolidation->divergence_sequence);
    }

    #[Test]
    public function a_bus_past_the_divergence_point_cannot_be_merged(): void
    {
        [$admin, $consolidation, $source, $target] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertOk();

        // The source bus reaches the stop where the routes part company.
        $source->stopProgress()
            ->where('sequence_number', $consolidation->divergence_sequence)
            ->update(['state' => 'DEPARTED']);

        // Merging now strands everyone waiting further along the old route.
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/execute", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_bus_short_of_the_divergence_point_can_be_merged(): void
    {
        [$admin, $consolidation, $source] = $this->proposal();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertOk();

        $source->stopProgress()->where('sequence_number', 1)->update(['state' => 'DEPARTED']);

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/execute", [],
            $this->authHeader($admin))->assertOk();
    }

    // ====================================================================
    // EXECUTION
    // ====================================================================

    /**
     * @return array{0: User, 1: TripConsolidation, 2: Trip, 3: Trip}
     */
    private function executedMerge(int $sourceAboard = 5, int $targetAboard = 5): array
    {
        [$admin, $consolidation, $source, $target] = $this->proposal($sourceAboard, $targetAboard);

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/notify", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/execute", [],
            $this->authHeader($admin))->assertOk();

        return [$admin, TripConsolidation::find($consolidation->id), $source->fresh(), $target->fresh()];
    }

    #[Test]
    public function execution_stands_the_source_trip_down(): void
    {
        [, $consolidation, $source] = $this->executedMerge();

        $this->assertSame(ConsolidationStatus::EXECUTED, $consolidation->status);
        $this->assertSame(TripStatus::CANCELLED, $source->status);
        $this->assertNotNull($source->cancelled_at);
    }

    #[Test]
    public function a_stood_down_trip_points_at_the_one_carrying_its_passengers(): void
    {
        [, , $source, $target] = $this->executedMerge();

        // Otherwise a rider following the old trip sees a cancellation and no
        // way to find where their bus went.
        $this->assertSame((string) $target->id, (string) $source->merged_into_trip_id);
    }

    #[Test]
    public function the_passengers_move_onto_the_target(): void
    {
        [, , , $target] = $this->executedMerge(sourceAboard: 6, targetAboard: 3);

        $this->assertSame(9, $target->occupied_seat_count);
    }

    #[Test]
    public function a_merge_cannot_execute_twice(): void
    {
        [$admin, $consolidation] = $this->executedMerge();

        $this->postJson("/api/v1/consolidations/{$consolidation->id}/execute", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function execution_is_written_to_the_audit_log(): void
    {
        [, $consolidation] = $this->executedMerge();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'CONSOLIDATION_EXECUTED',
            'record_id' => $consolidation->id,
        ]);
    }

    // ====================================================================
    // EXPIRY — BG-11
    // ====================================================================

    #[Test]
    public function a_proposal_nobody_decided_expires(): void
    {
        [, $consolidation] = $this->proposal();

        $this->travel(31)->minutes();

        (new ProposeConsolidations)->handle(app(ConsolidationService::class));

        $this->assertSame(
            ConsolidationStatus::EXPIRED,
            TripConsolidation::find($consolidation->id)->status,
        );
    }

    #[Test]
    public function an_expired_proposal_cannot_be_approved(): void
    {
        [$admin, $consolidation] = $this->proposal();

        $this->travel(31)->minutes();

        // Approving it would cancel a bus on occupancy figures from half an
        // hour ago.
        $this->postJson("/api/v1/consolidations/{$consolidation->id}/approve", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function the_system_proposes_under_its_own_identity(): void
    {
        $this->createAdmin();

        [, $source] = $this->runningTrip($this->routeWithStops(0.01), 40, 2);
        [, $target] = $this->runningTrip($this->routeWithStops(-0.01), 50, 2);

        (new ProposeConsolidations)->handle(app(ConsolidationService::class));

        $consolidation = TripConsolidation::first();

        // BR-512 — "somebody proposed this" is exactly the answer an audit
        // trail exists to prevent.
        $this->assertNotNull($consolidation);
        $this->assertNotNull($consolidation->proposed_by_id);
        $this->assertTrue(User::find($consolidation->proposed_by_id)->is_system);
    }

    #[Test]
    public function the_system_identity_can_never_log_in(): void
    {
        $system = User::systemActor();

        $this->assertNotNull($system);
        $this->assertFalse($system->is_active);

        // is_active = false is rejected by the auth middleware on every
        // request, so the identity is attributable but unusable.
        $this->postJson('/api/v1/auth/login', [
            'email' => $system->email,
            'password' => 'password',
        ])->assertStatus(401);
    }

    #[Test]
    public function the_system_identity_is_not_a_manageable_account(): void
    {
        $admin = $this->createAdmin();
        $system = User::systemActor();

        // It is an audit subject, not a colleague. Nobody gets to activate it,
        // edit it, or delete it and orphan every audit row it signed.
        $this->putJson("/api/v1/users/{$system->id}", ['first_name' => 'Hijacked'],
            $this->authHeader($admin))->assertStatus(403);

        $this->patchJson("/api/v1/users/{$system->id}/status", ['is_active' => true],
            $this->authHeader($admin))->assertStatus(403);
    }

    #[Test]
    public function the_system_identity_is_absent_from_the_staff_list(): void
    {
        $admin = $this->createAdmin();

        $emails = array_column(
            $this->getJson('/api/v1/users', $this->authHeader($admin))->assertOk()->json('data'),
            'email',
        );

        $this->assertNotContains(User::systemActor()->email, $emails);
    }
}
