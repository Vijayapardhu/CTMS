<?php

namespace Tests\Feature\Hardening;

use App\Enums\DriverStatus;
use App\Models\Bus;
use App\Models\DataAccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a driver can reach on the people endpoints.
 *
 * Five routes are reachable by a driver only because their route gate is
 * coarse — `/users/{id}`, `/students/{id}` and `/drivers/{id}/status` are not
 * behind `role:ADMIN`. The gate is deliberately coarse: record-level scope is
 * a policy's job, not a route's. This file is the proof that the policies
 * actually do it, because "the policy handles it" is an assertion until
 * something checks.
 *
 * The shape being locked down: a driver reaches **their own** records and
 * nothing else, and even on their own record they cannot reach the fields
 * that are somebody else's decision.
 */
class DriverScopeTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // USERS
    // ====================================================================

    #[Test]
    public function a_driver_can_read_their_own_user_record(): void
    {
        $driver = $this->createDriver();

        $this->getJson("/api/v1/users/{$driver->id}", $this->authHeader($driver))->assertOk();
    }

    #[Test]
    public function a_driver_cannot_read_another_users_record(): void
    {
        $driver = $this->createDriver();

        foreach ([$this->createDriver(), $this->createStudent(), $this->createAdmin()] as $other) {
            $this->getJson("/api/v1/users/{$other->id}", $this->authHeader($driver))
                ->assertStatus(403);
        }
    }

    #[Test]
    public function a_driver_can_edit_their_own_profile(): void
    {
        $driver = $this->createDriver();

        $this->putJson("/api/v1/users/{$driver->id}", [
            'first_name' => 'Updated',
        ], $this->authHeader($driver))->assertOk();

        $this->assertSame('Updated', $driver->fresh()->first_name);
    }

    #[Test]
    public function a_driver_cannot_edit_another_users_profile(): void
    {
        $driver = $this->createDriver();
        $other = $this->createDriver();

        $this->putJson("/api/v1/users/{$other->id}", [
            'first_name' => 'Hijacked',
        ], $this->authHeader($driver))->assertStatus(403);

        $this->assertNotSame('Hijacked', $other->fresh()->first_name);
    }

    #[Test]
    public function a_driver_cannot_grant_themselves_a_role_or_reactivation(): void
    {
        $driver = $this->createDriver();

        // Even on their own record: `role` and `is_active` are off `$fillable`
        // and absent from the FormRequest, so the payload is ignored rather
        // than obeyed.
        $this->putJson("/api/v1/users/{$driver->id}", [
            'first_name' => 'Still Me',
            'role' => 'ADMIN',
            'is_active' => true,
            'is_system' => true,
        ], $this->authHeader($driver))->assertOk();

        $fresh = $driver->fresh();

        $this->assertTrue($fresh->isDriver());
        $this->assertFalse($fresh->is_system);
    }

    // ====================================================================
    // STUDENTS — a driver reaches none of them
    // ====================================================================

    #[Test]
    public function a_driver_cannot_read_a_student_record(): void
    {
        $driver = $this->createDriver();
        $student = $this->createStudent();

        // A driver carries children but does not get their personal file.
        // What they need on the road is the stop manifest, which is a
        // different endpoint with a different shape.
        $this->getJson("/api/v1/students/{$student->student->id}", $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_edit_a_student_record(): void
    {
        $driver = $this->createDriver();
        $student = $this->createStudent();

        $this->putJson("/api/v1/students/{$student->student->id}", [
            'emergency_contact_name' => 'Changed',
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_list_students(): void
    {
        $this->getJson('/api/v1/students', $this->authHeader($this->createDriver()))
            ->assertStatus(403);
    }

    #[Test]
    public function reading_a_student_record_as_a_driver_writes_no_access_log(): void
    {
        $driver = $this->createDriver();
        $student = $this->createStudent();

        $this->getJson("/api/v1/students/{$student->student->id}", $this->authHeader($driver))
            ->assertStatus(403);

        // The refusal happens before the access is recorded, so a blocked
        // attempt does not pollute BR-501's record of who actually looked.
        $this->assertSame(0, DataAccessLog::count());
    }

    // ====================================================================
    // DRIVERS
    // ====================================================================

    #[Test]
    public function a_driver_can_read_their_own_driver_record(): void
    {
        $driver = $this->createDriver();

        $this->getJson("/api/v1/drivers/{$driver->driver->id}", $this->authHeader($driver))
            ->assertOk();
    }

    #[Test]
    public function a_driver_cannot_read_another_drivers_record(): void
    {
        $driver = $this->createDriver();
        $other = $this->createDriver();

        $this->getJson("/api/v1/drivers/{$other->driver->id}", $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_can_set_their_own_duty_status(): void
    {
        $driver = $this->createDriver();

        $this->patchJson("/api/v1/drivers/{$driver->driver->id}/status", [
            'status' => DriverStatus::OFF_DUTY->value,
        ], $this->authHeader($driver))->assertOk();

        $this->assertSame(DriverStatus::OFF_DUTY, $driver->driver->fresh()->status);
    }

    #[Test]
    public function a_driver_cannot_set_another_drivers_duty_status(): void
    {
        $driver = $this->createDriver();
        $other = $this->createDriver();

        // Marking a colleague available is how somebody ends up rostered onto
        // a bus they never agreed to drive.
        $this->patchJson("/api/v1/drivers/{$other->driver->id}/status", [
            'status' => DriverStatus::AVAILABLE->value,
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_edit_their_own_licence(): void
    {
        $driver = $this->createDriver();

        // A licence expiry the holder can edit is not a compliance record.
        $this->putJson("/api/v1/drivers/{$driver->driver->id}", [
            'license_expiry_date' => now()->addYears(5)->toDateString(),
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_assign_themselves_a_bus(): void
    {
        $driver = $this->createDriver();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus", [
            'bus_id' => Bus::factory()->create()->id,
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_list_the_workforce(): void
    {
        $this->getJson('/api/v1/drivers', $this->authHeader($this->createDriver()))
            ->assertStatus(403);
    }
}
