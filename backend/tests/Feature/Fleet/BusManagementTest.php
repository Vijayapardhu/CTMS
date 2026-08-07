<?php

namespace Tests\Feature\Fleet;

use App\Enums\BusStatus;
use App\Models\AuditLog;
use App\Models\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-02 — bus management.
 */
class BusManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function busPayload(array $overrides = []): array
    {
        return array_merge([
            'registration_number' => 'KA-01-AB-1234',
            'vehicle_name' => 'Campus Shuttle 1',
            'model' => 'Tata Starbus 2024',
            'year_of_manufacture' => 2024,
            'seating_capacity' => 45,
            'fuel_type' => 'DIESEL',
        ], $overrides);
    }

    // ====================================================================
    // LISTING AND READING
    // ====================================================================

    #[Test]
    public function any_authenticated_user_can_list_buses(): void
    {
        Bus::factory()->count(3)->create();

        foreach ([$this->createAdmin(), $this->createDriver(), $this->createStudent()] as $user) {
            $this->getJson('/api/v1/buses', $this->authHeader($user))
                ->assertOk()
                ->assertJsonPath('pagination.total', 3);
        }
    }

    #[Test]
    public function listing_buses_requires_authentication(): void
    {
        Bus::factory()->create();

        $this->getJson('/api/v1/buses')->assertStatus(401);
    }

    #[Test]
    public function buses_can_be_filtered_by_status(): void
    {
        $admin = $this->createAdmin();
        Bus::factory()->count(2)->create();
        Bus::factory()->inMaintenance()->create();

        $this->getJson('/api/v1/buses?status=MAINTENANCE', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function an_invalid_status_filter_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/buses?status=EXPLODED', $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    #[Test]
    public function a_wildcard_search_does_not_match_every_bus(): void
    {
        $admin = $this->createAdmin();
        Bus::factory()->count(3)->create();

        // A raw "%" must be treated as a literal, not as "match everything".
        $this->getJson('/api/v1/buses?search=%25', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function a_single_bus_can_be_read(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->getJson("/api/v1/buses/{$bus->id}", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.id', $bus->id);
    }

    #[Test]
    public function reading_an_unknown_bus_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/buses/019fd73c-0000-7000-8000-000000000000', $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Bus not found.');
    }

    // ====================================================================
    // CREATING
    // ====================================================================

    #[Test]
    public function an_admin_can_add_a_bus(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/buses', $this->busPayload(), $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.registration_number', 'KA-01-AB-1234')
            ->assertJsonPath('data.status', 'AVAILABLE');

        $this->assertDatabaseHas('buses', ['registration_number' => 'KA-01-AB-1234']);
    }

    #[Test]
    public function a_driver_cannot_add_a_bus(): void
    {
        $driver = $this->createDriver();

        $this->postJson('/api/v1/buses', $this->busPayload(), $this->authHeader($driver))
            ->assertStatus(403);

        $this->assertDatabaseCount('buses', 0);
    }

    #[Test]
    public function a_student_cannot_add_a_bus(): void
    {
        $student = $this->createStudent();

        $this->postJson('/api/v1/buses', $this->busPayload(), $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function adding_a_bus_requires_authentication(): void
    {
        $this->postJson('/api/v1/buses', $this->busPayload())->assertStatus(401);
    }

    #[Test]
    public function a_new_bus_always_starts_available(): void
    {
        $admin = $this->createAdmin();

        // Even if the client asks for RUNNING, a bus enters the fleet parked.
        $this->postJson('/api/v1/buses', $this->busPayload(['status' => 'RUNNING']), $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'AVAILABLE');
    }

    #[Test]
    public function it_rejects_a_duplicate_registration_number(): void
    {
        $admin = $this->createAdmin();
        Bus::factory()->create(['registration_number' => 'KA-01-AB-1234']);

        $this->postJson('/api/v1/buses', $this->busPayload(), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['registration_number']]);
    }

    #[Test]
    public function registration_numbers_are_compared_case_insensitively(): void
    {
        $admin = $this->createAdmin();
        Bus::factory()->create(['registration_number' => 'KA-01-AB-1234']);

        $this->postJson('/api/v1/buses', $this->busPayload(['registration_number' => 'ka-01-ab-1234']),
            $this->authHeader($admin))
            ->assertStatus(422);
    }

    #[Test]
    public function it_validates_the_bus_payload(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/buses', [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'registration_number', 'vehicle_name', 'model',
                    'year_of_manufacture', 'seating_capacity', 'fuel_type',
                ],
            ]);
    }

    #[Test]
    public function it_rejects_an_absurd_seating_capacity(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/buses', $this->busPayload(['seating_capacity' => 5000]), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['seating_capacity']]);

        $this->postJson('/api/v1/buses', $this->busPayload(['seating_capacity' => 0]), $this->authHeader($admin))
            ->assertStatus(422);
    }

    #[Test]
    public function creating_a_bus_writes_an_audit_record(): void
    {
        $admin = $this->createAdmin();

        $id = $this->postJson('/api/v1/buses', $this->busPayload(), $this->authHeader($admin))
            ->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'table_name' => 'buses',
            'record_id' => $id,
        ]);
    }

    // ====================================================================
    // UPDATING
    // ====================================================================

    #[Test]
    public function an_admin_can_update_a_bus(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->putJson("/api/v1/buses/{$bus->id}", [
            'vehicle_name' => 'Renamed Shuttle',
            'seating_capacity' => 52,
        ], $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.vehicle_name', 'Renamed Shuttle');

        $this->assertSame(52, $bus->fresh()->seating_capacity);
    }

    #[Test]
    public function a_driver_cannot_update_a_bus(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create(['vehicle_name' => 'Original']);

        $this->putJson("/api/v1/buses/{$bus->id}", [
            'vehicle_name' => 'Hijacked',
        ], $this->authHeader($driver))->assertStatus(403);

        $this->assertSame('Original', $bus->fresh()->vehicle_name);
    }

    #[Test]
    public function status_cannot_be_changed_through_the_general_update(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->brokenDown()->create();

        // Slipping `status` into a normal update must not bypass the state
        // machine and put an unrepaired bus back into service.
        $this->putJson("/api/v1/buses/{$bus->id}", [
            'vehicle_name' => 'Still Broken',
            'status' => 'AVAILABLE',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(BusStatus::BREAKDOWN, $bus->fresh()->status);
    }

    #[Test]
    public function a_bus_may_keep_its_own_registration_number_on_update(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create(['registration_number' => 'KA-09-ZZ-9999']);

        $this->putJson("/api/v1/buses/{$bus->id}", [
            'registration_number' => 'KA-09-ZZ-9999',
            'vehicle_name' => 'Same Plate',
        ], $this->authHeader($admin))->assertOk();
    }

    // ====================================================================
    // STATUS TRANSITIONS
    // ====================================================================

    #[Test]
    public function an_admin_can_move_a_bus_through_a_legal_transition(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create(); // AVAILABLE

        $this->patchJson("/api/v1/buses/{$bus->id}/status", [
            'status' => 'MAINTENANCE',
            'reason' => 'Scheduled service',
        ], $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.status', 'MAINTENANCE');

        $this->assertSame(BusStatus::MAINTENANCE, $bus->fresh()->status);
    }

    #[Test]
    public function a_broken_bus_cannot_go_straight_back_into_service(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->brokenDown()->create();

        // BREAKDOWN -> AVAILABLE must go via MAINTENANCE.
        $this->patchJson("/api/v1/buses/{$bus->id}/status", [
            'status' => 'AVAILABLE',
        ], $this->authHeader($admin))
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(BusStatus::BREAKDOWN, $bus->fresh()->status);
    }

    #[Test]
    public function a_broken_bus_can_return_to_service_after_maintenance(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->brokenDown()->create();

        $this->patchJson("/api/v1/buses/{$bus->id}/status", ['status' => 'MAINTENANCE'],
            $this->authHeader($admin))->assertOk();

        $this->patchJson("/api/v1/buses/{$bus->id}/status", ['status' => 'AVAILABLE'],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function re_asserting_the_current_status_is_a_no_op(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->patchJson("/api/v1/buses/{$bus->id}/status", ['status' => 'AVAILABLE'],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function a_driver_cannot_change_bus_status(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->patchJson("/api/v1/buses/{$bus->id}/status", ['status' => 'MAINTENANCE'],
            $this->authHeader($driver))->assertStatus(403);

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function an_unknown_status_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->patchJson("/api/v1/buses/{$bus->id}/status", ['status' => 'ON_FIRE'],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    #[Test]
    public function a_status_change_is_audited_with_both_values(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->patchJson("/api/v1/buses/{$bus->id}/status", [
            'status' => 'MAINTENANCE',
            'reason' => 'Brake inspection',
        ], $this->authHeader($admin))->assertOk();

        $log = AuditLog::where('action', 'BUS_STATUS_CHANGED')->first();

        $this->assertNotNull($log);
        $this->assertSame('AVAILABLE', $log->old_values['status']);
        $this->assertSame('MAINTENANCE', $log->new_values['status']);
        $this->assertSame('Brake inspection', $log->new_values['reason']);
        $this->assertSame($admin->id, $log->user_id);
    }

    // ====================================================================
    // RETIRING
    // ====================================================================

    #[Test]
    public function an_admin_can_retire_a_bus(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->deleteJson("/api/v1/buses/{$bus->id}", [], $this->authHeader($admin))
            ->assertOk();

        // Soft deleted: operational history still references this row.
        $this->assertSoftDeleted('buses', ['id' => $bus->id]);
    }

    #[Test]
    public function a_running_bus_cannot_be_retired(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->running()->create();

        $this->deleteJson("/api/v1/buses/{$bus->id}", [], $this->authHeader($admin))
            ->assertStatus(409);

        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_student_cannot_retire_a_bus(): void
    {
        $student = $this->createStudent();
        $bus = Bus::factory()->create();

        $this->deleteJson("/api/v1/buses/{$bus->id}", [], $this->authHeader($student))
            ->assertStatus(403);

        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_retired_bus_disappears_from_the_listing(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        Bus::factory()->create();

        $this->deleteJson("/api/v1/buses/{$bus->id}", [], $this->authHeader($admin))->assertOk();

        $this->getJson('/api/v1/buses', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }
}
