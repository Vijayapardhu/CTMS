<?php

namespace Tests\Feature\Hardening;

use App\Enums\AccessLevel;
use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G3-2 — the three mutations that serve both the subject and the office.
 *
 * `PUT /users/{id}`, `PUT /students/{id}` and `PATCH /drivers/{id}/status`
 * carry no role gate, because a student edits their own contact details and a
 * driver marks themselves off duty. Route middleware cannot say "the subject
 * OR an administrator at tier X", so the check lives in the policy — and until
 * now the policy said only `isAdmin()`, which let read-only oversight edit
 * anybody's record.
 *
 * The rule these tests hold the code to:
 *
 *   the subject themselves, for the fields the contract already allows
 *   OR an administrator meeting the tier the rest of that resource demands
 *
 * and never "any administrator".
 */
class SelfServiceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // PUT /users/{id} — the subject, or SUPER_ADMIN
    // ====================================================================

    #[Test]
    public function a_person_may_still_edit_their_own_contact_details(): void
    {
        $student = $this->createStudent();

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Renamed',
            'city' => 'Kakinada',
        ], $this->authHeader($student))->assertOk();

        $this->assertSame('Renamed', $student->fresh()->first_name);
    }

    #[Test]
    public function a_viewer_cannot_edit_somebody_elses_account(): void
    {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);
        $student = $this->createStudent(['first_name' => 'Untouched']);

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Edited',
        ], $this->authHeader($viewer))->assertStatus(403);

        $this->assertSame('Untouched', $student->fresh()->first_name);
    }

    #[Test]
    public function a_transport_head_cannot_edit_somebody_elses_account_either(): void
    {
        // Account administration is where creating and deactivating accounts
        // already live, and that is SUPER_ADMIN.
        $head = $this->createAdminAt(AccessLevel::OPERATIONS);
        $student = $this->createStudent(['first_name' => 'Untouched']);

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Edited',
        ], $this->authHeader($head))->assertStatus(403);

        $this->assertSame('Untouched', $student->fresh()->first_name);
    }

    #[Test]
    public function a_super_admin_may_edit_another_account(): void
    {
        $root = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Corrected',
        ], $this->authHeader($root))->assertOk();

        $this->assertSame('Corrected', $student->fresh()->first_name);
    }

    #[Test]
    public function the_system_account_is_untouchable_at_every_tier(): void
    {
        $root = $this->createSuperAdmin();
        $system = User::factory()->create(['is_system' => true]);

        $this->putJson("/api/v1/users/{$system->id}", [
            'first_name' => 'Hijacked',
        ], $this->authHeader($root))->assertStatus(403);
    }

    // ====================================================================
    // PUT /students/{id} — the student, or OPERATIONS
    // ====================================================================

    #[Test]
    public function a_student_may_still_edit_their_own_record(): void
    {
        $student = $this->createStudent();
        $profile = Student::where('user_id', $student->id)->firstOrFail();

        $this->putJson("/api/v1/students/{$profile->id}", [
            'hostel_name' => 'Godavari Block',
        ], $this->authHeader($student))->assertOk();

        $this->assertSame('Godavari Block', $profile->fresh()->hostel_name);
    }

    #[Test]
    public function a_viewer_cannot_edit_a_student_record(): void
    {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);
        $profile = Student::factory()->create(['hostel_name' => 'Untouched']);

        $this->putJson("/api/v1/students/{$profile->id}", [
            'hostel_name' => 'Edited',
        ], $this->authHeader($viewer))->assertStatus(403);

        $this->assertSame('Untouched', $profile->fresh()->hostel_name);
    }

    #[Test]
    public function operations_may_edit_a_student_record(): void
    {
        $head = $this->createAdminAt(AccessLevel::OPERATIONS);
        $profile = Student::factory()->create();

        $this->putJson("/api/v1/students/{$profile->id}", [
            'hostel_name' => 'Krishna Block',
        ], $this->authHeader($head))->assertOk();
    }

    #[Test]
    public function a_student_still_cannot_grant_themselves_a_ticket(): void
    {
        $student = $this->createStudent();
        $profile = Student::where('user_id', $student->id)
            ->firstOrFail();
        $profile->forceFill(['has_valid_ticket' => false])->save();

        $this->putJson("/api/v1/students/{$profile->id}", [
            'hostel_name' => 'Godavari Block',
            'has_valid_ticket' => true,
        ], $this->authHeader($student))->assertOk();

        // The entitlement is paid for. The controller strips it for a
        // non-admin caller, and that has not changed.
        $this->assertFalse((bool) $profile->fresh()->has_valid_ticket);
    }

    #[Test]
    public function a_driver_cannot_reach_another_persons_student_record(): void
    {
        $driver = $this->createDriver();
        $profile = Student::factory()->create(['hostel_name' => 'Untouched']);

        $this->putJson("/api/v1/students/{$profile->id}", [
            'hostel_name' => 'Edited',
        ], $this->authHeader($driver))->assertStatus(403);

        $this->assertSame('Untouched', $profile->fresh()->hostel_name);
    }

    // ====================================================================
    // PATCH /drivers/{id}/status — the driver, or OPERATIONS
    // ====================================================================

    #[Test]
    public function a_driver_may_still_stand_themselves_down(): void
    {
        $driverUser = $this->createDriver();

        $this->patchJson("/api/v1/drivers/{$driverUser->driver->id}/status", [
            'status' => DriverStatus::OFF_DUTY->value,
        ], $this->authHeader($driverUser))->assertOk();

        $this->assertSame(DriverStatus::OFF_DUTY, $driverUser->driver->fresh()->status);
    }

    #[Test]
    public function a_viewer_cannot_stand_a_driver_down(): void
    {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);
        $driver = Driver::factory()->create(['status' => DriverStatus::AVAILABLE->value]);

        $this->patchJson("/api/v1/drivers/{$driver->id}/status", [
            'status' => DriverStatus::OFF_DUTY->value,
        ], $this->authHeader($viewer))->assertStatus(403);

        // Standing somebody down takes a bus off the road with them.
        $this->assertSame(DriverStatus::AVAILABLE, $driver->fresh()->status);
    }

    #[Test]
    public function operations_may_stand_a_driver_down(): void
    {
        $head = $this->createAdminAt(AccessLevel::OPERATIONS);
        $driver = Driver::factory()->create(['status' => DriverStatus::AVAILABLE->value]);

        $this->patchJson("/api/v1/drivers/{$driver->id}/status", [
            'status' => DriverStatus::OFF_DUTY->value,
        ], $this->authHeader($head))->assertOk();
    }

    #[Test]
    public function a_driver_cannot_stand_another_driver_down(): void
    {
        $mine = $this->createDriver();
        $theirs = Driver::factory()->create(['status' => DriverStatus::AVAILABLE->value]);

        $this->patchJson("/api/v1/drivers/{$theirs->id}/status", [
            'status' => DriverStatus::OFF_DUTY->value,
        ], $this->authHeader($mine))->assertStatus(403);

        $this->assertSame(DriverStatus::AVAILABLE, $theirs->fresh()->status);
    }

    // ====================================================================
    // PRIVILEGE FIELDS
    // ====================================================================

    #[Test]
    public function privilege_fields_cannot_be_escalated_through_a_profile_edit(): void
    {
        $student = $this->createStudent();

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Still A Student',
            'role' => 'ADMIN',
            'is_active' => true,
            'is_system' => true,
            'access_level' => 'SUPER_ADMIN',
        ], $this->authHeader($student))->assertOk();

        $fresh = $student->fresh();

        // None of these are accepted by UpdateUserRequest at all, so they
        // never reach the model. Asserted rather than assumed, because the
        // consequence of one slipping through is somebody granting themselves
        // the fleet.
        $this->assertTrue($fresh->isStudent());
        $this->assertFalse((bool) $fresh->is_system);
        $this->assertNull($fresh->accessLevel());
        $this->assertDatabaseMissing('admins', ['user_id' => $student->id]);
    }

    #[Test]
    public function a_supervisor_cannot_promote_themselves_through_their_own_profile(): void
    {
        $supervisor = $this->createAdminAt(AccessLevel::SUPPORT);

        $this->putJson("/api/v1/users/{$supervisor->id}", [
            'first_name' => 'Still A Supervisor',
            'access_level' => 'SUPER_ADMIN',
        ], $this->authHeader($supervisor))->assertOk();

        $this->assertSame(AccessLevel::SUPPORT, $supervisor->fresh()->accessLevel());
    }
}
