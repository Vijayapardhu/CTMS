<?php

namespace Tests\Feature\Network;

use App\Enums\RouteStatus;
use App\Models\AuditLog;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-05 — route management. Covers BR-203, BR-204, BR-205, BR-215.
 */
class RouteManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function routePayload(array $overrides = []): array
    {
        return array_merge([
            'route_name' => 'North Campus Line',
            'route_code' => 'RT-0001',
            'description' => 'Serves the northern hostels.',
            'total_distance_km' => 18.5,
            'estimated_duration_minutes' => 55,
            'start_point' => 'Hostel Block A',
            'end_point' => 'Main Campus Gate',
        ], $overrides);
    }

    // ====================================================================
    // LISTING AND READING
    // ====================================================================

    #[Test]
    public function any_authenticated_user_can_list_routes(): void
    {
        Route::factory()->count(3)->create();

        foreach ([$this->createAdmin(), $this->createDriver(), $this->createStudent()] as $user) {
            $this->getJson('/api/v1/routes', $this->authHeader($user))
                ->assertOk()
                ->assertJsonPath('pagination.total', 3);
        }
    }

    #[Test]
    public function listing_routes_requires_authentication(): void
    {
        $this->getJson('/api/v1/routes')->assertStatus(401);
    }

    #[Test]
    public function routes_can_be_filtered_by_status(): void
    {
        $admin = $this->createAdmin();
        Route::factory()->count(2)->create();
        Route::factory()->inactive()->create();

        $this->getJson('/api/v1/routes?status=INACTIVE', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function an_invalid_status_filter_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/routes?status=CLOSED', $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    #[Test]
    public function a_wildcard_search_does_not_match_every_route(): void
    {
        $admin = $this->createAdmin();
        Route::factory()->count(3)->create();

        $this->getJson('/api/v1/routes?search=%25', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function a_route_can_be_read_with_its_stops_in_order(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        RouteStop::factory()->for($route)->atSequence(2)->create(['stop_name' => 'Second']);
        RouteStop::factory()->for($route)->atSequence(1)->create(['stop_name' => 'First']);

        $response = $this->getJson("/api/v1/routes/{$route->id}", $this->authHeader($admin))
            ->assertOk();

        $this->assertSame('First', $response->json('data.stops.0.stop_name'));
        $this->assertSame('Second', $response->json('data.stops.1.stop_name'));
    }

    #[Test]
    public function reading_an_unknown_route_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/routes/019fd73c-0000-7000-8000-000000000000', $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Route not found.');
    }

    // ====================================================================
    // CREATING
    // ====================================================================

    #[Test]
    public function an_admin_can_create_a_route(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/routes', $this->routePayload(), $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.route_code', 'RT-0001')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.number_of_stops', 0);

        $this->assertDatabaseHas('routes', ['route_code' => 'RT-0001']);
    }

    #[Test]
    public function a_driver_cannot_create_a_route(): void
    {
        $driver = $this->createDriver();

        $this->postJson('/api/v1/routes', $this->routePayload(), $this->authHeader($driver))
            ->assertStatus(403);

        $this->assertDatabaseCount('routes', 0);
    }

    #[Test]
    public function a_student_cannot_create_a_route(): void
    {
        $student = $this->createStudent();

        $this->postJson('/api/v1/routes', $this->routePayload(), $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function creating_a_route_requires_authentication(): void
    {
        $this->postJson('/api/v1/routes', $this->routePayload())->assertStatus(401);
    }

    #[Test]
    public function it_validates_the_route_payload(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/routes', [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'route_name', 'route_code', 'total_distance_km',
                    'estimated_duration_minutes', 'start_point', 'end_point',
                ],
            ]);
    }

    #[Test]
    public function it_rejects_a_duplicate_route_code(): void
    {
        $admin = $this->createAdmin();
        Route::factory()->create(['route_code' => 'RT-0001']);

        $this->postJson('/api/v1/routes', $this->routePayload(), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['route_code']]);
    }

    #[Test]
    public function route_codes_are_compared_case_insensitively(): void
    {
        $admin = $this->createAdmin();
        Route::factory()->create(['route_code' => 'RT-0001']);

        $this->postJson('/api/v1/routes', $this->routePayload(['route_code' => 'rt-0001']),
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function it_rejects_a_duplicate_route_name(): void
    {
        $admin = $this->createAdmin();
        Route::factory()->create(['route_name' => 'North Campus Line']);

        $this->postJson('/api/v1/routes', $this->routePayload(['route_code' => 'RT-9999']),
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['route_name']]);
    }

    #[Test]
    public function it_rejects_an_implausible_distance_or_duration(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/routes', $this->routePayload(['total_distance_km' => 0]),
            $this->authHeader($admin))->assertStatus(422);

        $this->postJson('/api/v1/routes', $this->routePayload(['estimated_duration_minutes' => 5000]),
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function creating_a_route_writes_an_audit_record(): void
    {
        $admin = $this->createAdmin();

        $id = $this->postJson('/api/v1/routes', $this->routePayload(), $this->authHeader($admin))
            ->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'table_name' => 'routes',
            'record_id' => $id,
        ]);
    }

    // ====================================================================
    // UPDATING
    // ====================================================================

    #[Test]
    public function an_admin_can_update_a_route(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->putJson("/api/v1/routes/{$route->id}", [
            'route_name' => 'Renamed Line',
            'estimated_duration_minutes' => 70,
        ], $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.route_name', 'Renamed Line');

        $this->assertSame(70, $route->fresh()->estimated_duration_minutes);
    }

    #[Test]
    public function a_driver_cannot_update_a_route(): void
    {
        $driver = $this->createDriver();
        $route = Route::factory()->create(['route_name' => 'Original']);

        $this->putJson("/api/v1/routes/{$route->id}", ['route_name' => 'Hijacked'],
            $this->authHeader($driver))->assertStatus(403);

        $this->assertSame('Original', $route->fresh()->route_name);
    }

    #[Test]
    public function status_cannot_be_changed_through_the_general_update(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->inactive()->create();

        $this->putJson("/api/v1/routes/{$route->id}", [
            'route_name' => 'Still Inactive',
            'status' => 'ACTIVE',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(RouteStatus::INACTIVE, $route->fresh()->status);
    }

    #[Test]
    public function the_stop_count_cannot_be_forced_through_the_update(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create(['number_of_stops' => 0]);

        $this->putJson("/api/v1/routes/{$route->id}", [
            'route_name' => 'Faked Count',
            'number_of_stops' => 99,
        ], $this->authHeader($admin))->assertOk();

        // Derived from the stops themselves, never accepted from a client.
        $this->assertSame(0, $route->fresh()->number_of_stops);
    }

    #[Test]
    public function a_route_may_keep_its_own_code_on_update(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create(['route_code' => 'RT-0500']);

        $this->putJson("/api/v1/routes/{$route->id}", [
            'route_code' => 'RT-0500',
            'route_name' => 'Same Code',
        ], $this->authHeader($admin))->assertOk();
    }

    // ====================================================================
    // STATUS
    // ====================================================================

    #[Test]
    public function an_admin_can_change_a_route_status(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->patchJson("/api/v1/routes/{$route->id}/status", ['status' => 'MAINTENANCE'],
            $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.status', 'MAINTENANCE');

        $this->assertSame(RouteStatus::MAINTENANCE, $route->fresh()->status);
    }

    #[Test]
    public function a_status_change_is_audited_with_both_values(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->patchJson("/api/v1/routes/{$route->id}/status", ['status' => 'INACTIVE'],
            $this->authHeader($admin))->assertOk();

        $log = AuditLog::where('action', 'ROUTE_STATUS_CHANGED')->first();

        $this->assertNotNull($log);
        $this->assertSame('ACTIVE', $log->old_values['status']);
        $this->assertSame('INACTIVE', $log->new_values['status']);
    }

    #[Test]
    public function an_unknown_route_status_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->patchJson("/api/v1/routes/{$route->id}/status", ['status' => 'CLOSED'],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    #[Test]
    public function a_student_cannot_change_a_route_status(): void
    {
        $student = $this->createStudent();
        $route = Route::factory()->create();

        $this->patchJson("/api/v1/routes/{$route->id}/status", ['status' => 'INACTIVE'],
            $this->authHeader($student))->assertStatus(403);
    }

    // ====================================================================
    // RETIRING — BR-205
    // ====================================================================

    #[Test]
    public function an_admin_can_retire_an_empty_route(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->deleteJson("/api/v1/routes/{$route->id}", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertSoftDeleted('routes', ['id' => $route->id]);
    }

    #[Test]
    public function a_route_with_assigned_students_cannot_be_retired(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $route->id])->save();

        $this->deleteJson("/api/v1/routes/{$route->id}", [], $this->authHeader($admin))
            ->assertStatus(409);

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_route_with_active_schedules_cannot_be_retired(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        Schedule::factory()->for($route)->create();

        $this->deleteJson("/api/v1/routes/{$route->id}", [], $this->authHeader($admin))
            ->assertStatus(409);

        $this->assertDatabaseHas('routes', ['id' => $route->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_route_with_only_inactive_schedules_can_be_retired(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        Schedule::factory()->for($route)->inactive()->create();

        $this->deleteJson("/api/v1/routes/{$route->id}", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertSoftDeleted('routes', ['id' => $route->id]);
    }

    #[Test]
    public function a_student_cannot_retire_a_route(): void
    {
        $student = $this->createStudent();
        $route = Route::factory()->create();

        $this->deleteJson("/api/v1/routes/{$route->id}", [], $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function a_retired_route_disappears_from_the_listing(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();
        Route::factory()->create();

        $this->deleteJson("/api/v1/routes/{$route->id}", [], $this->authHeader($admin))->assertOk();

        $this->getJson('/api/v1/routes', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }
}
