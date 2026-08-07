<?php

namespace Tests\Feature\People;

use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\Route;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-04 — student records.
 *
 * Covers BR-158, BR-161 to BR-165. Student records carry hostel addresses and
 * emergency contacts, so the horizontal-access cases matter as much as the
 * happy path.
 */
class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // LISTING
    // ====================================================================

    #[Test]
    public function an_admin_can_list_students(): void
    {
        $admin = $this->createAdmin();
        $this->createStudent();
        $this->createStudent();

        $this->getJson('/api/v1/students', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    #[Test]
    public function a_student_cannot_list_students(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/students', $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_list_students(): void
    {
        $driver = $this->createDriver();

        $this->getJson('/api/v1/students', $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function listing_students_requires_authentication(): void
    {
        $this->getJson('/api/v1/students')->assertStatus(401);
    }

    #[Test]
    public function students_can_be_filtered_by_status(): void
    {
        $admin = $this->createAdmin();
        $this->createStudent();
        $this->createStudent(profileAttributes: ['status' => StudentStatus::SUSPENDED->value]);

        $this->getJson('/api/v1/students?status=SUSPENDED', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function unassigned_students_can_be_filtered(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        $assigned = $this->createStudent();
        $assigned->student->forceFill(['route_id' => $route->id])->save();
        $this->createStudent();
        $this->createStudent();

        // The term-start working queue.
        $this->getJson('/api/v1/students?unassigned=1', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    #[Test]
    public function students_can_be_filtered_by_route(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        $onRoute = $this->createStudent();
        $onRoute->student->forceFill(['route_id' => $route->id])->save();
        $this->createStudent();

        $this->getJson("/api/v1/students?route_id={$route->id}", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function the_page_size_is_capped(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/students?per_page=5000', $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    #[Test]
    public function a_wildcard_search_does_not_match_every_student(): void
    {
        $admin = $this->createAdmin();
        $this->createStudent();
        $this->createStudent();

        $this->getJson('/api/v1/students?search=%25', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    // ====================================================================
    // READING — BR-164
    // ====================================================================

    #[Test]
    public function a_student_can_read_their_own_record(): void
    {
        $user = $this->createStudent();

        $this->getJson("/api/v1/students/{$user->student->id}", $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->student->id);
    }

    #[Test]
    public function a_student_cannot_read_another_students_record(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();

        $this->getJson("/api/v1/students/{$bob->student->id}", $this->authHeader($alice))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_read_a_student_record(): void
    {
        $driver = $this->createDriver();
        $student = $this->createStudent();

        $this->getJson("/api/v1/students/{$student->student->id}", $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function an_admin_can_read_any_student_record(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->getJson("/api/v1/students/{$student->student->id}", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.id', $student->student->id);
    }

    #[Test]
    public function reading_an_unknown_student_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/students/019fd73c-0000-7000-8000-000000000000', $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Student not found.');
    }

    // ====================================================================
    // CREATING — BR-161, BR-162, BR-163
    // ====================================================================

    #[Test]
    public function an_admin_can_create_a_student_profile(): void
    {
        $admin = $this->createAdmin();
        $account = User::factory()->student()->create();

        $this->postJson('/api/v1/students', [
            'user_id' => $account->id,
            'registration_number' => 'REG900001',
            'department' => 'Computer Science',
            'year_of_study' => 2,
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('students', [
            'user_id' => $account->id,
            'registration_number' => 'REG900001',
            'status' => StudentStatus::ACTIVE->value,
        ]);
    }

    #[Test]
    public function a_student_profile_cannot_be_attached_to_a_driver_account(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();

        $this->postJson('/api/v1/students', [
            'user_id' => $driver->id,
            'registration_number' => 'REG900002',
            'department' => 'Mechanical',
            'year_of_study' => 1,
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertDatabaseCount('students', 0);
    }

    #[Test]
    public function an_account_cannot_have_two_student_profiles(): void
    {
        $admin = $this->createAdmin();
        $existing = $this->createStudent();

        $this->postJson('/api/v1/students', [
            'user_id' => $existing->id,
            'registration_number' => 'REG900003',
            'department' => 'Civil',
            'year_of_study' => 3,
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function it_rejects_a_duplicate_registration_number(): void
    {
        $admin = $this->createAdmin();
        $existing = $this->createStudent();
        $account = User::factory()->student()->create();

        $this->postJson('/api/v1/students', [
            'user_id' => $account->id,
            'registration_number' => $existing->student->registration_number,
            'department' => 'Civil',
            'year_of_study' => 3,
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['registration_number']]);
    }

    #[Test]
    public function it_validates_the_student_payload(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/students', [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['user_id', 'registration_number', 'department', 'year_of_study']]);
    }

    #[Test]
    public function it_rejects_an_implausible_year_of_study(): void
    {
        $admin = $this->createAdmin();
        $account = User::factory()->student()->create();

        $this->postJson('/api/v1/students', [
            'user_id' => $account->id,
            'registration_number' => 'REG900004',
            'department' => 'Physics',
            'year_of_study' => 12,
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['year_of_study']]);
    }

    #[Test]
    public function a_student_cannot_create_a_student_profile(): void
    {
        $student = $this->createStudent();
        $account = User::factory()->student()->create();

        $this->postJson('/api/v1/students', [
            'user_id' => $account->id,
            'registration_number' => 'REG900005',
            'department' => 'Physics',
            'year_of_study' => 1,
        ], $this->authHeader($student))->assertStatus(403);
    }

    // ====================================================================
    // UPDATING — BR-157, BR-158
    // ====================================================================

    #[Test]
    public function an_admin_can_update_a_student(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->putJson("/api/v1/students/{$student->student->id}", [
            'department' => 'Biotechnology',
            'hostel_name' => 'Block C',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame('Biotechnology', $student->student->fresh()->department);
    }

    #[Test]
    public function a_student_can_update_their_own_contact_details(): void
    {
        $student = $this->createStudent();

        $this->putJson("/api/v1/students/{$student->student->id}", [
            'hostel_room' => 'C-214',
            'emergency_contact_phone' => '+919876500099',
        ], $this->authHeader($student))->assertOk();

        $this->assertSame('C-214', $student->student->fresh()->hostel_room);
    }

    #[Test]
    public function a_student_cannot_update_another_students_record(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent(profileAttributes: ['department' => 'Original']);

        $this->putJson("/api/v1/students/{$bob->student->id}", ['department' => 'Hacked'],
            $this->authHeader($alice))->assertStatus(403);

        $this->assertSame('Original', $bob->student->fresh()->department);
    }

    #[Test]
    public function a_student_cannot_grant_themselves_a_transport_pass(): void
    {
        $student = $this->createStudent(profileAttributes: [
            'has_valid_ticket' => false,
            'ticket_expiry_date' => null,
        ]);

        // BR-157 — the direct financial-fraud path.
        $this->putJson("/api/v1/students/{$student->student->id}", [
            'has_valid_ticket' => true,
            'ticket_expiry_date' => now()->addYear()->toDateString(),
        ], $this->authHeader($student))->assertOk();

        $fresh = $student->student->fresh();

        $this->assertFalse($fresh->has_valid_ticket);
        $this->assertNull($fresh->ticket_expiry_date);
    }

    #[Test]
    public function an_admin_can_grant_a_transport_pass(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent(profileAttributes: ['has_valid_ticket' => false]);

        $this->putJson("/api/v1/students/{$student->student->id}", [
            'has_valid_ticket' => true,
            'ticket_expiry_date' => now()->addYear()->toDateString(),
        ], $this->authHeader($admin))->assertOk();

        $this->assertTrue($student->student->fresh()->has_valid_ticket);
    }

    #[Test]
    public function a_student_cannot_change_their_own_registration_number(): void
    {
        $student = $this->createStudent();
        $original = $student->student->registration_number;

        // BR-158 — it is the institutional identity key.
        $this->putJson("/api/v1/students/{$student->student->id}", [
            'registration_number' => 'REG000000',
        ], $this->authHeader($student))->assertOk();

        $this->assertSame($original, $student->student->fresh()->registration_number);
    }

    #[Test]
    public function status_cannot_be_changed_through_the_general_update(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent(profileAttributes: ['status' => StudentStatus::SUSPENDED->value]);

        $this->putJson("/api/v1/students/{$student->student->id}", [
            'department' => 'Chemistry',
            'status' => 'ACTIVE',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame(StudentStatus::SUSPENDED, $student->student->fresh()->status);
    }

    #[Test]
    public function transport_assignment_cannot_be_set_through_the_general_update(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $route = Route::factory()->withStops()->create();

        $this->putJson("/api/v1/students/{$student->student->id}", [
            'department' => 'Chemistry',
            'route_id' => $route->id,
        ], $this->authHeader($admin))->assertOk();

        $this->assertNull($student->student->fresh()->route_id);
    }

    #[Test]
    public function updating_writes_an_audit_record(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->putJson("/api/v1/students/{$student->student->id}", ['department' => 'Audited'],
            $this->authHeader($admin))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'UPDATE',
            'table_name' => 'students',
            'record_id' => $student->student->id,
        ]);
    }

    // ====================================================================
    // STATUS — BR-156
    // ====================================================================

    #[Test]
    public function an_admin_can_suspend_a_student(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/students/{$student->student->id}/status", ['status' => 'SUSPENDED'],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(StudentStatus::SUSPENDED, $student->student->fresh()->status);
    }

    #[Test]
    public function suspending_a_student_clears_their_transport_assignment(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $stop = $route->stops()->first();
        $student = $this->createStudent();

        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $stop->id,
        ], $this->authHeader($admin))->assertOk();

        $this->patchJson("/api/v1/students/{$student->student->id}/status", ['status' => 'SUSPENDED'],
            $this->authHeader($admin))->assertOk();

        // BR-156: a suspended student must not remain counted in occupancy.
        $fresh = $student->student->fresh();

        $this->assertNull($fresh->route_id);
        $this->assertNull($fresh->pickup_stop_id);
        $this->assertNull($fresh->transport_assigned_at);
    }

    #[Test]
    public function a_status_change_is_audited(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/students/{$student->student->id}/status", ['status' => 'INACTIVE'],
            $this->authHeader($admin))->assertOk();

        $log = AuditLog::where('action', 'STUDENT_STATUS_CHANGED')->first();

        $this->assertNotNull($log);
        $this->assertSame('ACTIVE', $log->old_values['status']);
        $this->assertSame('INACTIVE', $log->new_values['status']);
    }

    #[Test]
    public function a_student_cannot_change_their_own_status(): void
    {
        $student = $this->createStudent(profileAttributes: ['status' => StudentStatus::SUSPENDED->value]);

        $this->patchJson("/api/v1/students/{$student->student->id}/status", ['status' => 'ACTIVE'],
            $this->authHeader($student))->assertStatus(403);

        $this->assertSame(StudentStatus::SUSPENDED, $student->student->fresh()->status);
    }

    #[Test]
    public function an_unknown_student_status_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/students/{$student->student->id}/status", ['status' => 'GRADUATED'],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    // ====================================================================
    // DELETING
    // ====================================================================

    #[Test]
    public function an_admin_can_remove_a_student(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->deleteJson("/api/v1/students/{$student->student->id}", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertSoftDeleted('students', ['id' => $student->student->id]);
    }

    #[Test]
    public function a_student_cannot_remove_themselves(): void
    {
        $student = $this->createStudent();

        $this->deleteJson("/api/v1/students/{$student->student->id}", [], $this->authHeader($student))
            ->assertStatus(403);

        $this->assertDatabaseHas('students', ['id' => $student->student->id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_listing_never_exposes_password_hashes(): void
    {
        $admin = $this->createAdmin();
        $this->createStudent();

        $response = $this->getJson('/api/v1/students', $this->authHeader($admin))->assertOk();

        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }
}
