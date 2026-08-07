<?php

namespace App\Services\Auth;

use App\Enums\AccessLevel;
use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Exceptions\AuthenticationException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication use cases: login, registration, refresh and logout.
 *
 * Authorization is NOT decided here — this class only establishes identity.
 */
class AuthService
{
    /**
     * A real bcrypt hash of a random throwaway string, compared against when
     * no user matches so that login timing is the same either way.
     */
    private const DECOY_HASH = '$2y$12$10/9T.jvz6Vlu2Dvtp1ijuBhuzrxcIb1o0Re.qos1UL5n7ED5vg9G';

    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Authenticate a user by credentials and issue a token pair.
     *
     * @return array{access_token: array, refresh_token: array, user: array}
     *
     * @throws AuthenticationException
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        // Always run a hash comparison, even when no user matched, so the
        // response time does not reveal whether the address is registered.
        // The decoy must be a structurally valid bcrypt hash — Laravel's
        // hasher throws on anything it does not recognise as bcrypt.
        $hash = $user?->password ?? self::DECOY_HASH;
        $passwordMatches = Hash::check($password, $hash);

        if (! $user || ! $passwordMatches) {
            $this->audit->log(
                action: 'LOGIN_FAILED',
                table: 'users',
                recordId: $user?->getKey(),
                new: ['email' => $email, 'reason' => 'invalid_credentials'],
                actor: null,
            );

            // Deliberately identical message for "no such user" and "wrong
            // password" — no account enumeration.
            throw new AuthenticationException('Invalid email or password.');
        }

        if (! $user->is_active) {
            $this->audit->log(
                action: 'LOGIN_FAILED',
                table: 'users',
                recordId: $user->getKey(),
                new: ['email' => $email, 'reason' => 'account_inactive'],
                actor: null,
            );

            throw new AuthenticationException('This account has been deactivated.');
        }

        $user->updateLoginAt();

        $this->audit->log(
            action: 'LOGIN',
            table: 'users',
            recordId: $user->getKey(),
            actor: $user,
        );

        return $this->tokenResponse($user);
    }

    /**
     * Register a new user together with its role-specific profile.
     *
     * The whole operation is one transaction: a user without its profile row
     * would be a half-created account that can log in but has no identity.
     *
     * @param  array<string, mixed>  $data
     * @return array{access_token: array, refresh_token: array, user: array}
     */
    public function register(array $data, ?User $actor = null): array
    {
        $role = $data['role'] instanceof UserRole
            ? $data['role']
            : UserRole::from((string) $data['role']);

        $user = DB::transaction(function () use ($data, $role) {
            $user = new User([
                'email' => $data['email'],
                'phone_number' => $data['phone_number'] ?? null,
                'password' => $data['password'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
            ]);

            // Privileged columns are set explicitly, never mass-assigned.
            $user->role = $role;
            $user->is_active = true;
            $user->save();

            $this->createProfile($user, $role, $data);

            return $user;
        });

        $this->audit->log(
            action: 'REGISTER',
            table: 'users',
            recordId: $user->getKey(),
            new: ['email' => $user->email, 'role' => $role->value],
            actor: $actor ?? $user,
        );

        return $this->tokenResponse($user->fresh());
    }

    /**
     * Exchange a valid refresh token for a new token pair.
     *
     * The presented refresh token is revoked on use, so a stolen refresh token
     * is single-use and its theft becomes detectable.
     *
     * @return array{access_token: array, refresh_token: array, user: array}
     *
     * @throws AuthenticationException
     */
    public function refresh(string $refreshToken): array
    {
        $claims = $this->tokens->parse($refreshToken, TokenService::typeRefresh());

        $user = User::find($claims->sub);

        if (! $user || ! $user->is_active) {
            throw new AuthenticationException('This account is no longer active.');
        }

        $this->tokens->revoke($claims);

        return $this->tokenResponse($user);
    }

    /**
     * Revoke the presented access token.
     */
    public function logout(object $claims, User $user): void
    {
        $this->tokens->revoke($claims);

        $this->audit->log(
            action: 'LOGOUT',
            table: 'users',
            recordId: $user->getKey(),
            actor: $user,
        );
    }

    /**
     * Revoke every token belonging to the user.
     */
    public function logoutEverywhere(User $user): void
    {
        $this->tokens->revokeAllForUser($user);

        $this->audit->log(
            action: 'LOGOUT_ALL',
            table: 'users',
            recordId: $user->getKey(),
            actor: $user,
        );
    }

    /**
     * Present a user for API output, including their role profile.
     *
     * @return array<string, mixed>
     */
    public function presentUser(User $user): array
    {
        $data = [
            'id' => $user->getKey(),
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->getFullName(),
            'role' => $user->role?->value,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];

        $data['profile'] = match ($user->role) {
            UserRole::STUDENT => $user->student?->only([
                'id', 'registration_number', 'department', 'year_of_study',
                'has_valid_ticket', 'ticket_expiry_date', 'status',
            ]),
            UserRole::DRIVER => $user->driver?->only([
                'id', 'license_number', 'license_class', 'license_expiry_date',
                'status', 'total_trips',
            ]),
            UserRole::ADMIN => $user->admin?->only([
                'id', 'designation', 'department', 'access_level',
            ]),
            default => null,
        };

        return $data;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * Create the profile row that matches the user's role.
     *
     * @param  array<string, mixed>  $data
     */
    private function createProfile(User $user, UserRole $role, array $data): void
    {
        match ($role) {
            UserRole::STUDENT => $user->student()->create([
                'registration_number' => $data['registration_number'],
                'department' => $data['department'],
                'year_of_study' => (string) $data['year_of_study'],
                'hostel_name' => $data['hostel_name'] ?? null,
                'hostel_room' => $data['hostel_room'] ?? null,
                'emergency_contact' => $data['emergency_contact'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'has_valid_ticket' => false,
                'status' => 'ACTIVE',
            ]),
            // `status` is not mass assignable on Driver — duty status is owned
            // by DriverService — so a new driver is stamped AVAILABLE explicitly.
            UserRole::DRIVER => tap($user->driver()->make([
                'license_number' => $data['license_number'],
                'license_class' => $data['license_class'],
                'license_expiry_date' => $data['license_expiry_date'],
            ]), function ($driver) {
                $driver->status = DriverStatus::AVAILABLE;
                $driver->save();
            }),
            UserRole::ADMIN => tap($user->admin()->create([
                'designation' => $data['designation'],
                'department' => $data['department'],
            ]), function ($admin) use ($data) {
                // Set explicitly rather than mass-assigned: `access_level` is
                // privilege, so it is off `$fillable` and can only be written
                // here, after the caller's authority has been checked.
                //
                // Defaults to the least privileged level. Elevation is a
                // separate, audited administrative action.
                $admin->forceFill([
                    'access_level' => isset($data['access_level'])
                        ? AccessLevel::from((string) $data['access_level'])->value
                        : AccessLevel::VIEWER->value,
                ])->save();
            }),
        };
    }

    /**
     * @return array{access_token: array, refresh_token: array, user: array}
     */
    private function tokenResponse(User $user): array
    {
        return [
            'access_token' => $this->tokens->issueAccessToken($user),
            'refresh_token' => $this->tokens->issueRefreshToken($user),
            'user' => $this->presentUser($user),
        ];
    }
}
