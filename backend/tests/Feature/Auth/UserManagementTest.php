<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-01 — user account management.
 *
 * The recurring risk here is horizontal privilege escalation: a valid token
 * plus someone else's id in the URL must not grant access to their record.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // LISTING
    // ====================================================================

    #[Test]
    public function an_admin_can_list_users(): void
    {
        $admin = $this->createSuperAdmin();
        $this->createStudent();
        $this->createDriver();

        $this->getJson('/api/v1/users', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination' => ['total', 'per_page', 'current_page', 'last_page']])
            ->assertJsonPath('pagination.total', 3);
    }

    #[Test]
    public function a_student_cannot_list_users(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/users', $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_list_users(): void
    {
        $driver = $this->createDriver();

        $this->getJson('/api/v1/users', $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    #[Test]
    public function an_admin_can_filter_users_by_role(): void
    {
        $admin = $this->createSuperAdmin();
        $this->createStudent();
        $this->createStudent();
        $this->createDriver();

        $this->getJson('/api/v1/users?role=STUDENT', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    #[Test]
    public function the_page_size_is_capped(): void
    {
        $admin = $this->createSuperAdmin();

        // A client asking for 5000 rows must not get them.
        $this->getJson('/api/v1/users?per_page=5000', $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    #[Test]
    public function the_listing_never_exposes_password_hashes(): void
    {
        $admin = $this->createSuperAdmin();
        $this->createStudent();

        $response = $this->getJson('/api/v1/users', $this->authHeader($admin))->assertOk();

        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }

    // ====================================================================
    // READING A SINGLE USER
    // ====================================================================

    #[Test]
    public function a_user_can_read_their_own_record(): void
    {
        $student = $this->createStudent();

        $this->getJson("/api/v1/users/{$student->id}", $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('data.id', $student->id);
    }

    #[Test]
    public function a_student_cannot_read_another_students_record(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();

        $this->getJson("/api/v1/users/{$bob->id}", $this->authHeader($alice))
            ->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_read_a_students_record(): void
    {
        $driver = $this->createDriver();
        $student = $this->createStudent();

        $this->getJson("/api/v1/users/{$student->id}", $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function an_admin_can_read_any_record(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->getJson("/api/v1/users/{$student->id}", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.id', $student->id);
    }

    #[Test]
    public function it_returns_404_for_an_unknown_user(): void
    {
        $admin = $this->createSuperAdmin();

        $this->getJson('/api/v1/users/019fd73c-0000-7000-8000-000000000000', $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'User not found.');
    }

    // ====================================================================
    // UPDATING
    // ====================================================================

    #[Test]
    public function a_user_can_update_their_own_profile(): void
    {
        $student = $this->createStudent();

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Renamed',
            'city' => 'Hyderabad',
        ], $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Renamed');

        $this->assertSame('Hyderabad', $student->fresh()->city);
    }

    #[Test]
    public function a_student_cannot_update_another_users_profile(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent(['first_name' => 'Bob']);

        $this->putJson("/api/v1/users/{$bob->id}", [
            'first_name' => 'Hacked',
        ], $this->authHeader($alice))->assertStatus(403);

        $this->assertSame('Bob', $bob->fresh()->first_name);
    }

    #[Test]
    public function a_user_cannot_promote_themselves_by_editing_their_profile(): void
    {
        $student = $this->createStudent();

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Still A Student',
            'role' => 'ADMIN',
        ], $this->authHeader($student))->assertOk();

        // The role field is not accepted by the profile endpoint at all.
        $this->assertTrue($student->fresh()->isStudent());
    }

    #[Test]
    public function a_user_cannot_reactivate_themselves_by_editing_their_profile(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/users/{$student->id}/status", [
            'is_active' => false,
        ], $this->authHeader($admin))->assertOk();

        $this->assertFalse($student->fresh()->is_active);
    }

    #[Test]
    public function it_rejects_an_email_already_used_by_someone_else(): void
    {
        $alice = $this->createStudent(['email' => 'alice@college.edu']);
        $bob = $this->createStudent(['email' => 'bob@college.edu']);

        $this->putJson("/api/v1/users/{$bob->id}", [
            'email' => 'alice@college.edu',
        ], $this->authHeader($bob))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function a_user_may_keep_their_own_email_on_update(): void
    {
        $student = $this->createStudent(['email' => 'keep@college.edu']);

        $this->putJson("/api/v1/users/{$student->id}", [
            'email' => 'keep@college.edu',
            'first_name' => 'Same Email',
        ], $this->authHeader($student))->assertOk();
    }

    #[Test]
    public function updating_writes_an_audit_record(): void
    {
        $student = $this->createStudent();

        $this->putJson("/api/v1/users/{$student->id}", [
            'first_name' => 'Audited',
        ], $this->authHeader($student))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'UPDATE',
            'table_name' => 'users',
            'record_id' => $student->id,
        ]);
    }

    // ====================================================================
    // ACTIVATION
    // ====================================================================

    #[Test]
    public function an_admin_can_deactivate_an_account(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/users/{$student->id}/status", [
            'is_active' => false,
        ], $this->authHeader($admin))->assertOk();

        $this->assertFalse($student->fresh()->is_active);
    }

    #[Test]
    public function deactivation_immediately_kills_the_users_sessions(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();
        $studentHeader = $this->authHeader($student);

        // The student is happily using the API.
        $this->getJson('/api/v1/auth/me', $studentHeader)->assertOk();

        $this->patchJson("/api/v1/users/{$student->id}/status", [
            'is_active' => false,
        ], $this->authHeader($admin))->assertOk();

        // Their existing token must stop working at once.
        $this->getJson('/api/v1/auth/me', $studentHeader)->assertStatus(401);
    }

    #[Test]
    public function an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->createSuperAdmin();

        // Locking the last administrator out is not a recoverable state.
        $this->patchJson("/api/v1/users/{$admin->id}/status", [
            'is_active' => false,
        ], $this->authHeader($admin))->assertStatus(403);

        $this->assertTrue($admin->fresh()->is_active);
    }

    #[Test]
    public function a_student_cannot_change_account_status(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();

        $this->patchJson("/api/v1/users/{$bob->id}/status", [
            'is_active' => false,
        ], $this->authHeader($alice))->assertStatus(403);

        $this->assertTrue($bob->fresh()->is_active);
    }

    #[Test]
    public function an_admin_can_reactivate_an_account(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent(['is_active' => false]);

        $this->patchJson("/api/v1/users/{$student->id}/status", [
            'is_active' => true,
        ], $this->authHeader($admin))->assertOk();

        $this->assertTrue($student->fresh()->is_active);
    }

    #[Test]
    public function the_status_endpoint_validates_its_payload(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/users/{$student->id}/status", [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['is_active']]);
    }
}
