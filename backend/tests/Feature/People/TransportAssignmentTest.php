<?php

namespace Tests\Feature\People;

use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-04 — seating a student on a route.
 *
 * Covers BR-150 to BR-160. This is the join between the People and Network
 * modules and the point where a mistake puts a child at a stop their bus
 * never visits.
 */
class TransportAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function assign(array $payload, $actor, string $studentProfileId)
    {
        return $this->postJson("/api/v1/students/{$studentProfileId}/assign-transport",
            $payload, $this->authHeader($actor));
    }

    // ====================================================================
    // HAPPY PATH
    // ====================================================================

    #[Test]
    public function an_admin_can_assign_transport(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $pickup = $route->stops()->first();
        $dropoff = $route->stops()->skip(1)->first();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $pickup->id,
            'dropoff_stop_id' => $dropoff->id,
        ], $admin, $student->student->id)->assertOk();

        $fresh = $student->student->fresh();

        $this->assertSame($route->id, $fresh->route_id);
        $this->assertSame($pickup->id, $fresh->pickup_stop_id);
        $this->assertSame($dropoff->id, $fresh->dropoff_stop_id);
        $this->assertNotNull($fresh->transport_assigned_at);
    }

    #[Test]
    public function a_drop_off_stop_is_optional(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        $this->assertNull($student->student->fresh()->dropoff_stop_id);
    }

    #[Test]
    public function assignment_is_audited(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'UPDATE',
            'table_name' => 'students',
            'record_id' => $student->student->id,
        ]);
    }

    #[Test]
    public function reassigning_replaces_the_previous_assignment(): void
    {
        $admin = $this->createAdmin();
        $first = Route::factory()->withStops()->create();
        $second = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $first->id,
            'pickup_stop_id' => $first->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        $this->assign([
            'route_id' => $second->id,
            'pickup_stop_id' => $second->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        // BR-150: at most one active assignment.
        $this->assertSame($second->id, $student->student->fresh()->route_id);
    }

    #[Test]
    public function an_admin_can_clear_a_transport_assignment(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        $this->deleteJson("/api/v1/students/{$student->student->id}/assign-transport", [],
            $this->authHeader($admin))->assertOk();

        $fresh = $student->student->fresh();

        $this->assertNull($fresh->route_id);
        $this->assertNull($fresh->pickup_stop_id);
    }

    // ====================================================================
    // ELIGIBILITY — BR-151, BR-152
    // ====================================================================

    #[Test]
    public function a_suspended_student_cannot_be_assigned_transport(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent(profileAttributes: ['status' => StudentStatus::SUSPENDED->value]);

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);

        $this->assertNull($student->student->fresh()->route_id);
    }

    #[Test]
    public function an_inactive_student_cannot_be_assigned_transport(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent(profileAttributes: ['status' => StudentStatus::INACTIVE->value]);

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function a_student_without_a_valid_pass_cannot_be_assigned_transport(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent(profileAttributes: [
            'has_valid_ticket' => false,
            'ticket_expiry_date' => null,
        ]);

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function a_student_with_an_expired_pass_cannot_be_assigned_transport(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent(profileAttributes: [
            'has_valid_ticket' => true,
            'ticket_expiry_date' => now()->subDay(),
        ]);

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function an_open_ended_pass_counts_as_valid(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent(profileAttributes: [
            'has_valid_ticket' => true,
            'ticket_expiry_date' => null,
        ]);

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();
    }

    // ====================================================================
    // ROUTE AND STOP INTEGRITY — BR-153, BR-154, BR-155, BR-204
    // ====================================================================

    #[Test]
    public function an_inactive_route_cannot_take_passengers(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->inactive()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function a_stop_from_a_different_route_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $otherRoute = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        // BR-153 — this would seat the student where their bus never goes.
        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $otherRoute->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);

        $this->assertNull($student->student->fresh()->route_id);
    }

    #[Test]
    public function a_drop_off_only_stop_cannot_be_used_for_pickup(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        $stop = RouteStop::factory()->for($route)->atSequence(1)->dropoffOnly()->create();
        $route->syncStopCount();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $stop->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function a_pickup_only_stop_cannot_be_used_for_drop_off(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        $pickup = RouteStop::factory()->for($route)->atSequence(1)->create();
        $dropoff = RouteStop::factory()->for($route)->atSequence(2)->pickupOnly()->create();
        $route->syncStopCount();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $pickup->id,
            'dropoff_stop_id' => $dropoff->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function a_drop_off_stop_from_a_different_route_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $otherRoute = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
            'dropoff_stop_id' => $otherRoute->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function the_pickup_and_drop_off_stops_must_differ(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $stop = $route->stops()->first();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $stop->id,
            'dropoff_stop_id' => $stop->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    // ====================================================================
    // CAPACITY — BR-159, BR-160
    // ====================================================================

    /**
     * A route served by one bus of the given capacity.
     */
    private function routeWithCapacity(int $seats): Route
    {
        $route = Route::factory()->withStops()->create();

        Schedule::factory()->create([
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->withCapacity($seats)->create()->id,
        ]);

        return $route->fresh();
    }

    /**
     * Fill a route to exactly `$count` assigned students.
     */
    private function fillRoute(Route $route, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createStudent()->student->forceFill(['route_id' => $route->id])->save();
        }
    }

    #[Test]
    public function assignment_is_refused_when_the_route_is_at_capacity(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(3);
        $this->fillRoute($route, 3);
        $student = $this->createStudent();

        $response = $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);

        $this->assertSame(3, $response->json('errors.assigned'));
        $this->assertSame(3, $response->json('errors.assignable_capacity'));
        $this->assertNull($student->student->fresh()->route_id);
    }

    #[Test]
    public function assignment_succeeds_below_capacity(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(3);
        $this->fillRoute($route, 2);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();
    }

    #[Test]
    public function the_safety_margin_is_held_back(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 2]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(10);
        $this->fillRoute($route, 8);
        $student = $this->createStudent();

        // 10 seats less a 2-seat margin means 8 assignable; the 9th is refused.
        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function capacity_is_bounded_by_the_smallest_bus_on_the_route(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        // Morning run on a big bus, evening run on a small one. A student
        // assigned to the route rides both, so the small bus is the constraint.
        Schedule::factory()->create([
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->withCapacity(50)->create()->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
        ]);
        Schedule::factory()->create([
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->withCapacity(4)->create()->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '16:00:00',
            'arrival_time' => '17:00:00',
        ]);

        $this->fillRoute($route->fresh(), 4);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function an_inactive_schedule_does_not_contribute_capacity(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        Schedule::factory()->create([
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->withCapacity(2)->create()->id,
        ]);
        Schedule::factory()->inactive()->create([
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->withCapacity(60)->create()->id,
            'day_of_week' => 'TUESDAY',
        ]);

        $this->fillRoute($route->fresh(), 2);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertStatus(409);
    }

    #[Test]
    public function a_route_with_no_schedule_yet_has_no_capacity_limit(): void
    {
        $admin = $this->createAdmin();

        // At term start students are seated before the timetable is built.
        $route = Route::factory()->withStops()->create();
        $this->fillRoute($route, 40);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();
    }

    #[Test]
    public function capacity_can_be_exceeded_with_a_stated_reason(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(2);
        $this->fillRoute($route, 2);
        $student = $this->createStudent();

        // BR-160 — sometimes correct, never accidental.
        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
            'capacity_override_reason' => 'Sibling travels on this route; approved by the transport manager.',
        ], $admin, $student->student->id)->assertOk();

        $this->assertSame($route->id, $student->student->fresh()->route_id);
    }

    #[Test]
    public function an_override_is_separately_audited_with_its_reason(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(1);
        $this->fillRoute($route, 1);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
            'capacity_override_reason' => 'Medical requirement, approved by the transport manager.',
        ], $admin, $student->student->id)->assertOk();

        $log = AuditLog::where('action', 'ROUTE_CAPACITY_OVERRIDDEN')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($route->id, $log->record_id);
        $this->assertStringContainsString('Medical requirement', $log->new_values['reason']);
    }

    #[Test]
    public function a_trivial_override_reason_is_rejected(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(1);
        $this->fillRoute($route, 1);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
            'capacity_override_reason' => 'ok',
        ], $admin, $student->student->id)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['capacity_override_reason']]);
    }

    #[Test]
    public function re_assigning_a_student_already_on_the_route_consumes_no_new_seat(): void
    {
        config(['ctms.capacity.safety_margin_seats' => 0]);

        $admin = $this->createAdmin();
        $route = $this->routeWithCapacity(2);
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        $this->fillRoute($route, 1); // route now at 2/2

        // Changing this student's stop must not be refused for capacity.
        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->skip(1)->first()->id,
        ], $admin, $student->student->id)->assertOk();
    }

    // ====================================================================
    // AUTHORIZATION AND VALIDATION
    // ====================================================================

    #[Test]
    public function a_student_cannot_assign_their_own_transport(): void
    {
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $student, $student->student->id)->assertStatus(403);

        $this->assertNull($student->student->fresh()->route_id);
    }

    #[Test]
    public function a_driver_cannot_assign_transport(): void
    {
        $driver = $this->createDriver();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $driver, $student->student->id)->assertStatus(403);
    }

    #[Test]
    public function assigning_transport_requires_authentication(): void
    {
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ])->assertStatus(401);
    }

    #[Test]
    public function it_validates_the_assignment_payload(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->assign([], $admin, $student->student->id)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['route_id', 'pickup_stop_id']]);
    }

    #[Test]
    public function an_unknown_route_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => '019fd73c-0000-7000-8000-000000000000',
            'pickup_stop_id' => '019fd73c-0000-7000-8000-000000000001',
        ], $admin, $student->student->id)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['route_id']]);
    }

    #[Test]
    public function assigning_to_an_unknown_student_returns_404(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, '019fd73c-0000-7000-8000-000000000000')->assertStatus(404);
    }

    #[Test]
    public function a_student_cannot_clear_their_own_transport(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->assign([
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $admin, $student->student->id)->assertOk();

        $this->deleteJson("/api/v1/students/{$student->student->id}/assign-transport", [],
            $this->authHeader($student))->assertStatus(403);

        $this->assertNotNull($student->student->fresh()->route_id);
    }
}
