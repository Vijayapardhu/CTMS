<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-01 — password change.
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Ev3n!Str0nger';

    #[Test]
    public function a_user_can_change_their_own_password(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $this->authHeader($user))->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function the_new_password_works_for_login(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $this->authHeader($user))->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function the_old_password_stops_working(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $this->authHeader($user))->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertStatus(401);
    }

    #[Test]
    public function changing_the_password_kills_every_existing_session(): void
    {
        $user = $this->createStudent();
        $phone = $this->authHeader($user);
        $laptop = $this->authHeader($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $phone)->assertOk();

        // If the old password leaked, the attacker's token must die with it.
        $this->getJson('/api/v1/auth/me', $laptop)->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $phone)->assertStatus(401);
    }

    #[Test]
    public function it_rejects_a_wrong_current_password(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'not-my-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['current_password']]);

        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function it_rejects_a_weak_new_password(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function it_rejects_reusing_the_current_password(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => UserFactory::PASSWORD,
            'password_confirmation' => UserFactory::PASSWORD,
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(401);
    }

    #[Test]
    public function it_does_not_write_the_password_into_the_audit_trail(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $this->authHeader($user))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'PASSWORD_CHANGED',
        ]);

        $logs = AuditLog::all()->toJson();

        $this->assertStringNotContainsString(self::NEW_PASSWORD, $logs);
        $this->assertStringNotContainsString(UserFactory::PASSWORD, $logs);
    }
}
