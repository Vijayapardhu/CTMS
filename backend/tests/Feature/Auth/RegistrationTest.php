<?php

namespace Tests\Feature\Auth;

use App\Enums\AccessLevel;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-01 — registration.
 *
 * The central concern here is privilege: a public endpoint that accepts a
 * `role` field is a privilege-escalation hole unless it is constrained.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function studentPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'newstudent@college.edu',
            'phone_number' => '+919876543210',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
            'first_name' => 'Ananya',
            'last_name' => 'Reddy',
            'role' => 'STUDENT',
            'registration_number' => 'REG202601',
            'department' => 'Computer Science',
            'year_of_study' => 2,
        ], $overrides);
    }

    // ====================================================================
    // HAPPY PATH
    // ====================================================================

    #[Test]
    public function anyone_can_register_as_a_student(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->studentPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.user.role', 'STUDENT')
            ->assertJsonPath('data.user.email', 'newstudent@college.edu')
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user']]);

        $user = User::where('email', 'newstudent@college.edu')->first();

        $this->assertNotNull($user);
        $this->assertSame(UserRole::STUDENT, $user->role);
        $this->assertTrue($user->is_active);

        // The student profile row must exist — a user without one is a
        // half-created account.
        $this->assertDatabaseHas('students', [
            'user_id' => $user->id,
            'registration_number' => 'REG202601',
            'has_valid_ticket' => false,
        ]);
    }

    #[Test]
    public function the_issued_token_works_immediately(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->studentPayload())
            ->json('data.access_token.token');

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.role', 'STUDENT');
    }

    #[Test]
    public function it_stores_the_password_hashed(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload())->assertStatus(201);

        $user = User::where('email', 'newstudent@college.edu')->first();

        $this->assertNotSame('Str0ng!Passw0rd', $user->password);
        $this->assertTrue(password_verify('Str0ng!Passw0rd', $user->password));
    }

    // ====================================================================
    // PRIVILEGE ESCALATION
    // ====================================================================

    #[Test]
    public function a_stranger_cannot_register_themselves_as_an_admin(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'role' => 'ADMIN',
            'designation' => 'Fleet Manager',
            'department' => 'Transport',
            'access_level' => 'SUPER_ADMIN',
        ]))->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'newstudent@college.edu']);
    }

    #[Test]
    public function a_stranger_cannot_register_themselves_as_a_driver(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'role' => 'DRIVER',
            'license_number' => 'DL-000111',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYear()->toDateString(),
        ]))->assertStatus(403);

        $this->assertDatabaseCount('drivers', 0);
    }

    #[Test]
    public function a_student_cannot_escalate_by_registering_an_admin(): void
    {
        $student = $this->createStudent();

        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'role' => 'ADMIN',
            'designation' => 'Fleet Manager',
            'department' => 'Transport',
        ]), $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function lowercase_role_values_do_not_bypass_the_role_gate(): void
    {
        // Casing must be normalised at the edge, not compared loosely — a
        // lowercase "admin" must not slip past the privilege check.
        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'role' => 'admin',
            'designation' => 'Fleet Manager',
            'department' => 'Transport',
        ]))->assertStatus(403);
    }

    #[Test]
    public function an_admin_can_register_a_driver(): void
    {
        $admin = $this->createSuperAdmin();

        $this->postJson('/api/v1/users', $this->studentPayload([
            'email' => 'newdriver@college.edu',
            'phone_number' => '+919876500011',
            'role' => 'DRIVER',
            'license_number' => 'DL-778899',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->addYear()->toDateString(),
        ]), $this->authHeader($admin))->assertStatus(201)
            ->assertJsonPath('data.user.role', 'DRIVER');

        $this->assertDatabaseHas('drivers', ['license_number' => 'DL-778899']);
    }

    #[Test]
    public function an_admin_can_register_another_admin(): void
    {
        $admin = $this->createSuperAdmin();

        $this->postJson('/api/v1/users', $this->studentPayload([
            'email' => 'newadmin@college.edu',
            'phone_number' => '+919876500022',
            'role' => 'ADMIN',
            'designation' => 'Transport Officer',
            'department' => 'Transport',
            'access_level' => 'OPERATIONS',
        ]), $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('admins', ['access_level' => AccessLevel::OPERATIONS->value]);
    }

    #[Test]
    public function a_new_admin_defaults_to_the_least_privileged_access_level(): void
    {
        $admin = $this->createSuperAdmin();

        $this->postJson('/api/v1/users', $this->studentPayload([
            'email' => 'quietadmin@college.edu',
            'phone_number' => '+919876500033',
            'role' => 'ADMIN',
            'designation' => 'Support Desk',
            'department' => 'Transport',
            // access_level deliberately omitted
        ]), $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('admins', [
            'designation' => 'Support Desk',
            'access_level' => AccessLevel::VIEWER->value,
        ]);
    }

    #[Test]
    public function a_client_cannot_force_an_account_active_flag_or_id(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'id' => '11111111-1111-1111-1111-111111111111',
            'is_active' => false,
        ]))->assertStatus(201);

        $user = User::where('email', 'newstudent@college.edu')->first();

        $this->assertNotSame('11111111-1111-1111-1111-111111111111', $user->id);
        $this->assertTrue($user->is_active);
    }

    // ====================================================================
    // VALIDATION
    // ====================================================================

    #[Test]
    public function it_rejects_a_duplicate_email(): void
    {
        $existing = $this->createStudent(['email' => 'taken@college.edu']);

        $this->postJson('/api/v1/auth/register', $this->studentPayload(['email' => 'taken@college.edu']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function it_rejects_a_duplicate_registration_number(): void
    {
        $student = $this->createStudent();
        $number = $student->student->registration_number;

        $this->postJson('/api/v1/auth/register', $this->studentPayload(['registration_number' => $number]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['registration_number']]);
    }

    #[Test]
    public function it_rejects_a_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function it_rejects_a_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'password_confirmation' => 'Something!Else1',
        ]))->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function it_rejects_an_unknown_role(): void
    {
        $this->postJson('/api/v1/auth/register', $this->studentPayload(['role' => 'SUPERUSER']))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role']]);
    }

    #[Test]
    public function it_requires_the_profile_fields_for_the_chosen_role(): void
    {
        $payload = $this->studentPayload();
        unset($payload['registration_number'], $payload['department'], $payload['year_of_study']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['registration_number', 'department', 'year_of_study']]);
    }

    #[Test]
    public function an_admin_cannot_register_a_driver_with_an_expired_licence(): void
    {
        $admin = $this->createSuperAdmin();

        $this->postJson('/api/v1/users', $this->studentPayload([
            'email' => 'expired@college.edu',
            'phone_number' => '+919876500044',
            'role' => 'DRIVER',
            'license_number' => 'DL-EXPIRED',
            'license_class' => 'Heavy Vehicle',
            'license_expiry_date' => now()->subDay()->toDateString(),
        ]), $this->authHeader($admin))->assertStatus(422)
            ->assertJsonStructure(['errors' => ['license_expiry_date']]);
    }

    #[Test]
    public function a_failed_registration_leaves_nothing_behind(): void
    {
        // Duplicate registration number fails after the email passes, proving
        // the whole insert is one transaction.
        $student = $this->createStudent();

        $this->postJson('/api/v1/auth/register', $this->studentPayload([
            'registration_number' => $student->student->registration_number,
        ]))->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'newstudent@college.edu']);
        $this->assertSame(1, Student::count());
        $this->assertSame(0, Driver::count());
    }
}
