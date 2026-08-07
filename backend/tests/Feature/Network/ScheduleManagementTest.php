<?php

namespace Tests\Feature\Network;

use App\Enums\DayOfWeek;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-05 — weekly schedules.
 *
 * The invariant under test is BR-206/BR-207: on a given weekday a bus is in
 * one place at a time, and so is a driver. Double-booking either is what
 * strands a busload of students at a stop.
 */
class ScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function serviceableRoute(): Route
    {
        $route = Route::factory()->create();
        RouteStop::factory()->for($route)->atSequence(1)->create();
        $route->syncStopCount();

        return $route->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(array $overrides = []): array
    {
        return array_merge([
            'route_id' => $this->serviceableRoute()->id,
            'bus_id' => Bus::factory()->create()->id,
            'driver_id' => Driver::factory()->create()->id,
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
            'day_of_week' => 'MONDAY',
            'frequency' => 'WEEKDAYS',
            'expected_passenger_count' => 30,
        ], $overrides);
    }

    // ====================================================================
    // LISTING AND READING
    // ====================================================================

    #[Test]
    public function any_authenticated_user_can_list_schedules(): void
    {
        Schedule::factory()->count(2)->create();

        foreach ([$this->createAdmin(), $this->createDriver(), $this->createStudent()] as $user) {
            $this->getJson('/api/v1/schedules', $this->authHeader($user))
                ->assertOk()
                ->assertJsonPath('pagination.total', 2);
        }
    }

    #[Test]
    public function listing_schedules_requires_authentication(): void
    {
        $this->getJson('/api/v1/schedules')->assertStatus(401);
    }

    #[Test]
    public function schedules_can_be_filtered_by_day(): void
    {
        $admin = $this->createAdmin();
        Schedule::factory()->onDay(DayOfWeek::MONDAY)->create();
        Schedule::factory()->onDay(DayOfWeek::FRIDAY)->create();

        $this->getJson('/api/v1/schedules?day_of_week=FRIDAY', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function schedules_can_be_filtered_by_active_state(): void
    {
        $admin = $this->createAdmin();
        Schedule::factory()->create();
        Schedule::factory()->inactive()->create();

        $this->getJson('/api/v1/schedules?is_active=0', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function an_unknown_day_filter_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/schedules?day_of_week=CATURDAY', $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['day_of_week']]);
    }

    #[Test]
    public function reading_an_unknown_schedule_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/schedules/019fd73c-0000-7000-8000-000000000000', $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Schedule not found.');
    }

    // ====================================================================
    // CREATING
    // ====================================================================

    #[Test]
    public function an_admin_can_create_a_schedule(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(), $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.day_of_week', 'MONDAY')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseCount('schedules', 1);
    }

    #[Test]
    public function a_driver_cannot_create_a_schedule(): void
    {
        $driver = $this->createDriver();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(), $this->authHeader($driver))
            ->assertStatus(403);

        $this->assertDatabaseCount('schedules', 0);
    }

    #[Test]
    public function a_student_cannot_create_a_schedule(): void
    {
        $student = $this->createStudent();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(), $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function creating_a_schedule_requires_authentication(): void
    {
        $this->postJson('/api/v1/schedules', $this->schedulePayload())->assertStatus(401);
    }

    #[Test]
    public function it_validates_the_schedule_payload(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/schedules', [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['route_id', 'bus_id', 'driver_id', 'departure_time', 'arrival_time', 'day_of_week', 'frequency'],
            ]);
    }

    #[Test]
    public function it_accepts_times_without_seconds(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['departure_time' => '08:00', 'arrival_time' => '09:00']),
            $this->authHeader($admin))->assertStatus(201);
    }

    #[Test]
    public function arrival_must_be_later_than_departure(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['departure_time' => '09:00:00', 'arrival_time' => '08:00:00']),
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['arrival_time']]);
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/schedules', $this->schedulePayload([
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['end_date']]);
    }

    // ====================================================================
    // RESOURCE ELIGIBILITY — BR-203, BR-204
    // ====================================================================

    #[Test]
    public function a_route_with_no_stops_cannot_be_scheduled(): void
    {
        $admin = $this->createAdmin();
        $emptyRoute = Route::factory()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['route_id' => $emptyRoute->id]),
            $this->authHeader($admin))
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function an_inactive_route_cannot_be_scheduled(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->inactive()->create();
        RouteStop::factory()->for($route)->atSequence(1)->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['route_id' => $route->id]),
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_bus_in_maintenance_cannot_be_scheduled(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->inMaintenance()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['bus_id' => $bus->id]),
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_with_an_expired_licence_cannot_be_scheduled(): void
    {
        $admin = $this->createAdmin();
        $driver = Driver::factory()->licenceExpired()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['driver_id' => $driver->id]),
            $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // CONFLICT DETECTION — BR-206, BR-207, BR-208, BR-209
    // ====================================================================

    #[Test]
    public function a_bus_cannot_be_double_booked_on_the_same_day(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['bus_id' => $bus->id, 'departure_time' => '08:00:00', 'arrival_time' => '09:00:00']),
            $this->authHeader($admin))->assertStatus(201);

        $response = $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['bus_id' => $bus->id, 'departure_time' => '08:30:00', 'arrival_time' => '09:30:00']),
            $this->authHeader($admin))->assertStatus(409);

        $this->assertStringContainsString('bus', strtolower($response->json('message')));
        $this->assertDatabaseCount('schedules', 1);
    }

    #[Test]
    public function a_driver_cannot_be_double_booked_on_the_same_day(): void
    {
        $admin = $this->createAdmin();
        $driver = Driver::factory()->create();

        $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['driver_id' => $driver->id, 'departure_time' => '08:00:00', 'arrival_time' => '09:00:00']),
            $this->authHeader($admin))->assertStatus(201);

        $response = $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['driver_id' => $driver->id, 'departure_time' => '08:30:00', 'arrival_time' => '09:30:00']),
            $this->authHeader($admin))->assertStatus(409);

        $this->assertStringContainsString('driver', strtolower($response->json('message')));
    }

    #[Test]
    public function the_conflicting_schedule_is_identified(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $first = $this->postJson('/api/v1/schedules', $this->schedulePayload(['bus_id' => $bus->id]),
            $this->authHeader($admin))->json('data.id');

        $response = $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['bus_id' => $bus->id, 'departure_time' => '08:30:00', 'arrival_time' => '09:30:00']),
            $this->authHeader($admin))->assertStatus(409);

        // The operator needs to know *which* schedule blocks them.
        $this->assertSame($first, $response->json('errors.conflicting_schedule_id'));
    }

    #[Test]
    public function touching_time_windows_do_not_conflict(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['bus_id' => $bus->id, 'departure_time' => '08:00:00', 'arrival_time' => '09:00:00']),
            $this->authHeader($admin))->assertStatus(201);

        // BR-208: arriving at 09:00 frees the bus to depart at 09:00.
        $this->postJson('/api/v1/schedules',
            $this->schedulePayload(['bus_id' => $bus->id, 'departure_time' => '09:00:00', 'arrival_time' => '10:00:00']),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseCount('schedules', 2);
    }

    #[Test]
    public function the_same_bus_can_run_on_different_days(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['bus_id' => $bus->id, 'day_of_week' => 'MONDAY']),
            $this->authHeader($admin))->assertStatus(201);

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['bus_id' => $bus->id, 'day_of_week' => 'TUESDAY']),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseCount('schedules', 2);
    }

    #[Test]
    public function an_inactive_schedule_does_not_block_a_new_one(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        Schedule::factory()->inactive()->create([
            'bus_id' => $bus->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
        ]);

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['bus_id' => $bus->id]),
            $this->authHeader($admin))->assertStatus(201);
    }

    #[Test]
    public function schedules_in_non_overlapping_terms_do_not_conflict(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload([
            'bus_id' => $bus->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-31',
        ]), $this->authHeader($admin))->assertStatus(201);

        // BR-209: a schedule for the following term is not a clash.
        $this->postJson('/api/v1/schedules', $this->schedulePayload([
            'bus_id' => $bus->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-11-30',
        ]), $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseCount('schedules', 2);
    }

    #[Test]
    public function schedules_in_overlapping_terms_do_conflict(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload([
            'bus_id' => $bus->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
        ]), $this->authHeader($admin))->assertStatus(201);

        $this->postJson('/api/v1/schedules', $this->schedulePayload([
            'bus_id' => $bus->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-11-30',
        ]), $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function an_open_ended_schedule_conflicts_with_a_dated_one(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson('/api/v1/schedules', $this->schedulePayload(['bus_id' => $bus->id]),
            $this->authHeader($admin))->assertStatus(201);

        $this->postJson('/api/v1/schedules', $this->schedulePayload([
            'bus_id' => $bus->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-11-30',
        ]), $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // UPDATING — BR-211
    // ====================================================================

    #[Test]
    public function an_admin_can_update_a_schedule(): void
    {
        $admin = $this->createAdmin();
        $schedule = Schedule::factory()->create();

        $this->putJson("/api/v1/schedules/{$schedule->id}", ['expected_passenger_count' => 45],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(45, $schedule->fresh()->expected_passenger_count);
    }

    #[Test]
    public function a_partial_update_is_validated_against_the_merged_result(): void
    {
        $admin = $this->createAdmin();
        $schedule = Schedule::factory()->between('08:00:00', '09:00:00')->create();

        // Moving only the arrival earlier than the stored departure inverts
        // the window, even though the payload alone looks fine.
        $this->putJson("/api/v1/schedules/{$schedule->id}", ['arrival_time' => '07:00:00'],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['arrival_time']]);
    }

    #[Test]
    public function a_schedule_does_not_conflict_with_itself_on_update(): void
    {
        $admin = $this->createAdmin();
        $schedule = Schedule::factory()->create();

        $this->putJson("/api/v1/schedules/{$schedule->id}", ['expected_passenger_count' => 10],
            $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function an_update_that_creates_a_conflict_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $route = $this->serviceableRoute();

        Schedule::factory()->create([
            'bus_id' => $bus->id,
            'route_id' => $route->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
        ]);

        $second = Schedule::factory()->create([
            'bus_id' => $bus->id,
            'route_id' => $route->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '10:00:00',
            'arrival_time' => '11:00:00',
        ]);

        $this->putJson("/api/v1/schedules/{$second->id}", [
            'departure_time' => '08:30:00',
            'arrival_time' => '09:30:00',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_cannot_update_a_schedule(): void
    {
        $driver = $this->createDriver();
        $schedule = Schedule::factory()->create(['expected_passenger_count' => 30]);

        $this->putJson("/api/v1/schedules/{$schedule->id}", ['expected_passenger_count' => 99],
            $this->authHeader($driver))->assertStatus(403);

        $this->assertSame(30, $schedule->fresh()->expected_passenger_count);
    }

    // ====================================================================
    // ACTIVATION
    // ====================================================================

    #[Test]
    public function an_admin_can_deactivate_and_reactivate_a_schedule(): void
    {
        $admin = $this->createAdmin();
        $schedule = Schedule::factory()->create();

        $this->patchJson("/api/v1/schedules/{$schedule->id}/status", ['is_active' => false],
            $this->authHeader($admin))->assertOk();

        $this->assertFalse($schedule->fresh()->is_active);

        $this->patchJson("/api/v1/schedules/{$schedule->id}/status", ['is_active' => true],
            $this->authHeader($admin))->assertOk();

        $this->assertTrue($schedule->fresh()->is_active);
    }

    #[Test]
    public function reactivating_into_a_taken_slot_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $route = $this->serviceableRoute();

        $dormant = Schedule::factory()->inactive()->create([
            'bus_id' => $bus->id,
            'route_id' => $route->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
        ]);

        // The slot was taken while this schedule was switched off.
        Schedule::factory()->create([
            'bus_id' => $bus->id,
            'route_id' => $route->id,
            'day_of_week' => 'MONDAY',
            'departure_time' => '08:30:00',
            'arrival_time' => '09:30:00',
        ]);

        $this->patchJson("/api/v1/schedules/{$dormant->id}/status", ['is_active' => true],
            $this->authHeader($admin))->assertStatus(409);

        $this->assertFalse($dormant->fresh()->is_active);
    }

    #[Test]
    public function the_status_endpoint_validates_its_payload(): void
    {
        $admin = $this->createAdmin();
        $schedule = Schedule::factory()->create();

        $this->patchJson("/api/v1/schedules/{$schedule->id}/status", [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['is_active']]);
    }

    // ====================================================================
    // DELETING
    // ====================================================================

    #[Test]
    public function an_admin_can_delete_a_schedule(): void
    {
        $admin = $this->createAdmin();
        $schedule = Schedule::factory()->create();

        $this->deleteJson("/api/v1/schedules/{$schedule->id}", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertSoftDeleted('schedules', ['id' => $schedule->id]);
    }

    #[Test]
    public function a_student_cannot_delete_a_schedule(): void
    {
        $student = $this->createStudent();
        $schedule = Schedule::factory()->create();

        $this->deleteJson("/api/v1/schedules/{$schedule->id}", [], $this->authHeader($student))
            ->assertStatus(403);

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'deleted_at' => null]);
    }

    #[Test]
    public function creating_a_schedule_writes_an_audit_record(): void
    {
        $admin = $this->createAdmin();

        $id = $this->postJson('/api/v1/schedules', $this->schedulePayload(), $this->authHeader($admin))
            ->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'table_name' => 'schedules',
            'record_id' => $id,
        ]);
    }
}
