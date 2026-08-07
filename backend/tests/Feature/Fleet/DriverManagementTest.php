<?php

namespace Tests\Feature\Fleet;

use App\Enums\DriverStatus;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-03 — driver management, including bus assignment.
 */
class DriverManagementTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // LISTING AND READING
    // ====================================================================

    #[Test]
    public function an_admin_can_list_drivers(): void
    {
        $admin = $this->createAdmin();
        $this->createDriver();
        $this->createDriver();

        $this->getJson('/api/v1/drivers', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    #[Test]
    public function a_student_cannot_list_drivers(): void
    {
        $student = $this->createStudent();
        $this->createDriver();

        $this->getJson('/api/v1/drivers', $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_list_all_drivers(): void
    {
        $driver = $this->createDriver();

        // Licence numbers and violation history are personal data.
        $this->getJson('/api/v1/drivers', $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_driver_can_read_their_own_record(): void
    {
        $user = $this->createDriver();

        $this->getJson("/api/v1/drivers/{$user->driver->id}", $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->driver->id);
    }

    #[Test]
    public function a_driver_cannot_read_another_drivers_record(): void
    {
        $alice = $this->createDriver();
        $bob = $this->createDriver();

        $this->getJson("/api/v1/drivers/{$bob->driver->id}", $this->authHeader($alice))
            ->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_read_a_driver_record(): void
    {
        $student = $this->createStudent();
        $driver = $this->createDriver();

        $this->getJson("/api/v1/drivers/{$driver->driver->id}", $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function assignable_drivers_can_be_filtered(): void
    {
        $admin = $this->createAdmin();
        $this->createDriver();                                    // available, valid licence
        $this->createDriver(profileAttributes: ['status' => DriverStatus::LEAVE->value]);
        $this->createDriver(profileAttributes: ['license_expiry_date' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/drivers?assignable=1', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    // ====================================================================
    // CREATING
    // ====================================================================

    #[Test]
    public function an_admin_can_create_a_driver_profile(): void
    {
        $admin = $this->createAdmin();
        $account = User::factory()->driver()->create();

        $this->postJson('/api/v1/drivers', [
            'user_id' => $account->id,
            'license_number' => 'DL-556677',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYears(3)->toDateString(),
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('drivers', [
            'user_id' => $account->id,
            'license_number' => 'DL-556677',
            'status' => DriverStatus::AVAILABLE->value,
        ]);
    }

    #[Test]
    public function a_driver_profile_cannot_be_attached_to_a_student_account(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->postJson('/api/v1/drivers', [
            'user_id' => $student->id,
            'license_number' => 'DL-998877',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYears(3)->toDateString(),
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertDatabaseCount('drivers', 0);
    }

    #[Test]
    public function an_account_cannot_have_two_driver_profiles(): void
    {
        $admin = $this->createAdmin();
        $existing = $this->createDriver();

        $this->postJson('/api/v1/drivers', [
            'user_id' => $existing->id,
            'license_number' => 'DL-111222',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYears(3)->toDateString(),
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function it_rejects_an_expired_licence_on_creation(): void
    {
        $admin = $this->createAdmin();
        $account = User::factory()->driver()->create();

        $this->postJson('/api/v1/drivers', [
            'user_id' => $account->id,
            'license_number' => 'DL-000000',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->subDay()->toDateString(),
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['license_expiry_date']]);
    }

    #[Test]
    public function it_rejects_a_duplicate_licence_number(): void
    {
        $admin = $this->createAdmin();
        $existing = $this->createDriver();
        $account = User::factory()->driver()->create();

        $this->postJson('/api/v1/drivers', [
            'user_id' => $account->id,
            'license_number' => $existing->driver->license_number,
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYear()->toDateString(),
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['license_number']]);
    }

    #[Test]
    public function a_driver_cannot_create_a_driver_profile(): void
    {
        $driver = $this->createDriver();
        $account = User::factory()->driver()->create();

        $this->postJson('/api/v1/drivers', [
            'user_id' => $account->id,
            'license_number' => 'DL-343434',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYear()->toDateString(),
        ], $this->authHeader($driver))->assertStatus(403);
    }

    // ====================================================================
    // UPDATING
    // ====================================================================

    #[Test]
    public function an_admin_can_record_a_licence_renewal(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $newExpiry = now()->addYears(5)->toDateString();

        $this->putJson("/api/v1/drivers/{$driver->driver->id}", [
            'license_expiry_date' => $newExpiry,
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($newExpiry, $driver->driver->fresh()->license_expiry_date->toDateString());
    }

    #[Test]
    public function a_driver_cannot_edit_their_own_licence_details(): void
    {
        $driver = $this->createDriver();

        // Self-service licence renewal would defeat the compliance check.
        $this->putJson("/api/v1/drivers/{$driver->driver->id}", [
            'license_expiry_date' => now()->addYears(10)->toDateString(),
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function status_cannot_be_changed_through_the_general_update(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver(profileAttributes: ['status' => DriverStatus::LEAVE->value]);

        $this->putJson("/api/v1/drivers/{$driver->driver->id}", [
            'license_class' => 'Light Vehicle',
            'status' => 'AVAILABLE',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(DriverStatus::LEAVE, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_driver_profile_cannot_be_repointed_at_another_account(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $victim = $this->createDriver();
        $originalUserId = $driver->driver->user_id;

        $this->putJson("/api/v1/drivers/{$driver->driver->id}", [
            'user_id' => $victim->id,
            'license_class' => 'Light Vehicle',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($originalUserId, $driver->driver->fresh()->user_id);
    }

    // ====================================================================
    // DUTY STATUS
    // ====================================================================

    #[Test]
    public function a_driver_can_set_their_own_duty_status(): void
    {
        $driver = $this->createDriver();

        $this->patchJson("/api/v1/drivers/{$driver->driver->id}/status", [
            'status' => 'OFF_DUTY',
        ], $this->authHeader($driver))->assertOk();

        $this->assertSame(DriverStatus::OFF_DUTY, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_driver_cannot_set_another_drivers_status(): void
    {
        $alice = $this->createDriver();
        $bob = $this->createDriver();

        $this->patchJson("/api/v1/drivers/{$bob->driver->id}/status", [
            'status' => 'OFF_DUTY',
        ], $this->authHeader($alice))->assertStatus(403);

        $this->assertSame(DriverStatus::AVAILABLE, $bob->driver->fresh()->status);
    }

    #[Test]
    public function a_driver_on_a_trip_cannot_jump_straight_to_leave(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver(profileAttributes: ['status' => DriverStatus::ON_TRIP->value]);

        $this->patchJson("/api/v1/drivers/{$driver->driver->id}/status", [
            'status' => 'LEAVE',
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertSame(DriverStatus::ON_TRIP, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_driver_with_an_expired_licence_cannot_start_a_trip(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver(profileAttributes: [
            'license_expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->patchJson("/api/v1/drivers/{$driver->driver->id}/status", [
            'status' => 'ON_TRIP',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function an_unknown_duty_status_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();

        $this->patchJson("/api/v1/drivers/{$driver->driver->id}/status", [
            'status' => 'NAPPING',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    // ====================================================================
    // BUS ASSIGNMENT
    // ====================================================================

    #[Test]
    public function an_admin_can_assign_a_bus_to_a_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($bus->id, $driver->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function a_bus_cannot_be_assigned_to_two_drivers(): void
    {
        $admin = $this->createAdmin();
        $alice = $this->createDriver();
        $bob = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$alice->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/drivers/{$bob->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertNull($bob->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function reassigning_the_same_bus_to_the_same_driver_is_allowed(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function a_bus_under_maintenance_cannot_be_assigned(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->inMaintenance()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertNull($driver->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function a_driver_with_an_expired_licence_cannot_be_assigned_a_bus(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver(profileAttributes: [
            'license_expiry_date' => now()->subDay()->toDateString(),
        ]);
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_on_leave_cannot_be_assigned_a_bus(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver(profileAttributes: ['status' => DriverStatus::LEAVE->value]);
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_cannot_assign_a_bus_to_themselves(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => $bus->id,
        ], $this->authHeader($driver))->assertStatus(403);

        $this->assertNull($driver->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function assigning_an_unknown_bus_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => '019fd73c-0000-7000-8000-000000000000',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['bus_id']]);
    }

    #[Test]
    public function an_admin_can_release_a_bus_from_a_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();

        $this->deleteJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [],
            $this->authHeader($admin))->assertOk();

        $this->assertNull($driver->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function a_released_bus_can_be_given_to_another_driver(): void
    {
        $admin = $this->createAdmin();
        $alice = $this->createDriver();
        $bob = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$alice->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();
        $this->deleteJson("/api/v1/drivers/{$alice->driver->id}/assign-bus", [],
            $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/drivers/{$bob->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();

        $this->assertSame($bus->id, $bob->driver->fresh()->assigned_bus_id);
    }

    // ====================================================================
    // REMOVAL
    // ====================================================================

    #[Test]
    public function an_admin_can_remove_a_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();

        $this->deleteJson("/api/v1/drivers/{$driver->driver->id}", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertSoftDeleted('drivers', ['id' => $driver->driver->id]);
    }

    #[Test]
    public function removing_a_driver_frees_their_bus(): void
    {
        $admin = $this->createAdmin();
        $alice = $this->createDriver();
        $bob = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$alice->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();

        $this->deleteJson("/api/v1/drivers/{$alice->driver->id}", [], $this->authHeader($admin))
            ->assertOk();

        // The bus must not be stranded by the departing driver.
        $this->postJson("/api/v1/drivers/{$bob->driver->id}/assign-bus", ['bus_id' => $bus->id],
            $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function a_driver_cannot_remove_themselves(): void
    {
        $driver = $this->createDriver();

        $this->deleteJson("/api/v1/drivers/{$driver->driver->id}", [], $this->authHeader($driver))
            ->assertStatus(403);

        $this->assertDatabaseHas('drivers', ['id' => $driver->driver->id, 'deleted_at' => null]);
    }

    #[Test]
    public function removing_an_unknown_driver_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->deleteJson('/api/v1/drivers/019fd73c-0000-7000-8000-000000000000', [],
            $this->authHeader($admin))->assertStatus(404);
    }
}
