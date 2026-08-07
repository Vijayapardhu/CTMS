<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\TokenService;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-01 — authentication.
 *
 * Covers the happy path plus every way a caller can fail to prove identity.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = UserFactory::PASSWORD;

    // ====================================================================
    // LOGIN
    // ====================================================================

    #[Test]
    public function it_issues_a_token_pair_for_valid_credentials(): void
    {
        $user = $this->createStudent(['email' => 'kavya@college.edu']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'kavya@college.edu',
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message', 'code',
                'data' => [
                    'access_token' => ['token', 'token_type', 'expires_in', 'expires_at'],
                    'refresh_token' => ['token', 'token_type', 'expires_in', 'expires_at'],
                    'user' => ['id', 'email', 'role', 'profile'],
                ],
            ])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'STUDENT');

        // The password hash must never appear in a response body.
        $this->assertStringNotContainsString('password', strtolower($response->getContent()));
    }

    #[Test]
    public function it_records_the_login_timestamp(): void
    {
        $user = $this->createStudent(['last_login_at' => null]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    #[Test]
    public function it_rejects_a_wrong_password(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NotThePassword!1',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid email or password.');
    }

    #[Test]
    public function it_does_not_reveal_whether_an_email_is_registered(): void
    {
        $user = $this->createStudent();

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@college.edu',
            'password' => 'NotThePassword!1',
        ]);

        $known = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NotThePassword!1',
        ]);

        // Identical status and message: the endpoint is not an enumeration oracle.
        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode());
        $this->assertSame(
            $unknown->json('message'),
            $known->json('message'),
        );
    }

    #[Test]
    public function it_refuses_login_for_a_deactivated_account(): void
    {
        $user = $this->createStudent(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(401)
            ->assertJsonPath('message', 'This account has been deactivated.');
    }

    #[Test]
    public function it_validates_the_login_payload(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    #[Test]
    public function it_rate_limits_repeated_login_attempts(): void
    {
        $user = $this->createStudent();

        // The `auth` limiter allows 5 attempts per minute per email address.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    // ====================================================================
    // TOKEN VALIDATION
    // ====================================================================

    #[Test]
    public function it_returns_the_authenticated_profile(): void
    {
        $user = $this->createDriver();

        $this->getJson('/api/v1/auth/me', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'DRIVER')
            ->assertJsonPath('data.profile.license_class', 'Heavy Vehicle');
    }

    #[Test]
    public function it_rejects_a_request_with_no_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Authentication token is missing.');
    }

    #[Test]
    public function it_rejects_a_malformed_token(): void
    {
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer not.a.jwt'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_rejects_a_token_signed_with_the_wrong_key(): void
    {
        $user = $this->createStudent();

        // Mint a token under a different signing key, as a forger would.
        config(['auth.jwt.secret' => 'an-attackers-signing-key-that-is-long-enough']);
        $forged = app(TokenService::class)->issueAccessToken($user)['token'];
        config(['auth.jwt.secret' => 'testing-only-jwt-signing-key-do-not-use-in-production']);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$forged}"])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token signature is invalid.');
    }

    #[Test]
    public function it_rejects_an_expired_token(): void
    {
        $user = $this->createStudent();
        $header = $this->authHeader($user);

        // Move past the access token's lifetime plus the clock-skew leeway.
        $this->travel(config('auth.jwt.expiration') + config('auth.jwt.leeway') + 60)->seconds();

        $this->getJson('/api/v1/auth/me', $header)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token has expired.');
    }

    #[Test]
    public function it_refuses_a_refresh_token_used_as_an_access_token(): void
    {
        $user = $this->createStudent();
        $refresh = app(TokenService::class)->issueRefreshToken($user)['token'];

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$refresh}"])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token is not valid for this operation.');
    }

    #[Test]
    public function it_rejects_a_token_issued_for_another_audience(): void
    {
        $user = $this->createStudent();

        config(['auth.jwt.audience' => 'some-other-service']);
        $foreign = app(TokenService::class)->issueAccessToken($user)['token'];
        config(['auth.jwt.audience' => 'ctms-api']);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$foreign}"])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token was issued for a different service.');
    }

    #[Test]
    public function it_rejects_a_token_whose_account_was_deleted(): void
    {
        $user = $this->createStudent();
        $header = $this->authHeader($user);

        $user->delete();

        $this->getJson('/api/v1/auth/me', $header)
            ->assertStatus(401)
            ->assertJsonPath('message', 'The account for this token no longer exists.');
    }

    #[Test]
    public function it_rejects_a_still_valid_token_once_the_account_is_deactivated(): void
    {
        $user = $this->createStudent();
        $header = $this->authHeader($user);

        // The token is cryptographically fine, but the account is not.
        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/auth/me', $header)
            ->assertStatus(401)
            ->assertJsonPath('message', 'This account has been deactivated.');
    }

    // ====================================================================
    // LOGOUT / REVOCATION
    // ====================================================================

    #[Test]
    public function it_revokes_the_presented_token_on_logout(): void
    {
        $user = $this->createStudent();
        $header = $this->authHeader($user);

        $this->postJson('/api/v1/auth/logout', [], $header)->assertOk();

        // The same token must no longer be accepted.
        $this->getJson('/api/v1/auth/me', $header)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token has been revoked.');
    }

    #[Test]
    public function it_leaves_other_sessions_alive_after_a_single_logout(): void
    {
        $user = $this->createStudent();
        $phone = $this->authHeader($user);
        $laptop = $this->authHeader($user);

        $this->postJson('/api/v1/auth/logout', [], $phone)->assertOk();

        $this->getJson('/api/v1/auth/me', $laptop)->assertOk();
    }

    #[Test]
    public function it_revokes_every_session_on_logout_all(): void
    {
        $user = $this->createStudent();
        $phone = $this->authHeader($user);
        $laptop = $this->authHeader($user);

        $this->postJson('/api/v1/auth/logout-all', [], $phone)->assertOk();

        $this->getJson('/api/v1/auth/me', $laptop)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token has been revoked.');
    }

    #[Test]
    public function it_requires_authentication_to_log_out(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    // ====================================================================
    // REFRESH
    // ====================================================================

    #[Test]
    public function it_exchanges_a_refresh_token_for_a_new_pair(): void
    {
        $user = $this->createStudent();
        $refresh = app(TokenService::class)->issueRefreshToken($user)['token'];

        $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'user']]);

        // The freshly issued access token works.
        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$response->json('data.access_token.token'),
        ])->assertOk();
    }

    #[Test]
    public function it_consumes_the_refresh_token_it_was_given(): void
    {
        $user = $this->createStudent();
        $refresh = app(TokenService::class)->issueRefreshToken($user)['token'];

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk();

        // Replaying the same refresh token must fail.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token has been revoked.');
    }

    #[Test]
    public function it_refuses_an_access_token_at_the_refresh_endpoint(): void
    {
        $user = $this->createStudent();
        $access = $this->tokenFor($user);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $access])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token is not valid for this operation.');
    }

    #[Test]
    public function it_refuses_to_refresh_a_deactivated_account(): void
    {
        $user = $this->createStudent();
        $refresh = app(TokenService::class)->issueRefreshToken($user)['token'];

        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertStatus(401);
    }

    #[Test]
    public function it_validates_the_refresh_payload(): void
    {
        $this->postJson('/api/v1/auth/refresh', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['refresh_token']]);
    }

    // ====================================================================
    // UNKNOWN ROUTES
    // ====================================================================

    #[Test]
    public function it_returns_the_standard_envelope_for_an_unknown_endpoint(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Endpoint not found.');
    }

    #[Test]
    public function it_rejects_a_wrong_http_method(): void
    {
        $this->getJson('/api/v1/auth/login')->assertStatus(405);
    }
}
