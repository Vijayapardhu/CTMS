<?php

namespace App\Services\Auth;

use App\Exceptions\AuthenticationException;
use App\Models\User;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues, validates and revokes JWT access tokens.
 *
 * Design notes:
 *  - Every token carries a unique `jti`. Logout adds that `jti` to a denylist
 *    held until the token's own expiry, which gives stateless JWTs real
 *    revocation without a DB round trip per request.
 *  - "Logout everywhere" and password changes bump a per-user epoch; any token
 *    issued before that epoch is rejected, so old sessions die immediately.
 *  - The `typ` claim separates access from refresh tokens, so a refresh token
 *    can never be replayed as an access token.
 */
class TokenService
{
    private const TYPE_ACCESS = 'access';

    private const TYPE_REFRESH = 'refresh';

    /** Cache key prefix for revoked token ids. */
    private const DENYLIST_PREFIX = 'jwt:denied:';

    /** Cache key prefix for the per-user "tokens issued before X are invalid" epoch. */
    private const EPOCH_PREFIX = 'jwt:epoch:';

    /**
     * Issue an access token for the given user.
     *
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string}
     */
    public function issueAccessToken(User $user): array
    {
        return $this->issue($user, self::TYPE_ACCESS, $this->accessTtl());
    }

    /**
     * Issue a refresh token for the given user. Refresh tokens are long-lived
     * and may only be exchanged at the refresh endpoint.
     *
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string}
     */
    public function issueRefreshToken(User $user): array
    {
        return $this->issue($user, self::TYPE_REFRESH, $this->refreshTtl());
    }

    /**
     * Decode and fully validate a token of the expected type.
     *
     * Validates: signature, algorithm, expiry, not-before, issuer, audience,
     * token type, denylist membership and the user's token epoch.
     *
     * @return object The decoded claims.
     *
     * @throws AuthenticationException on any validation failure.
     */
    public function parse(string $token, string $expectedType = self::TYPE_ACCESS): object
    {
        JWT::$leeway = (int) config('auth.jwt.leeway', 0);

        // Anchor the library's clock to Carbon's, so the whole application
        // agrees on "now" — and so time-dependent behaviour is testable.
        JWT::$timestamp = now()->timestamp;

        try {
            $claims = JWT::decode($token, new Key($this->secret(), $this->algorithm()));
        } catch (ExpiredException) {
            throw new AuthenticationException('Token has expired.');
        } catch (SignatureInvalidException) {
            throw new AuthenticationException('Token signature is invalid.');
        } catch (BeforeValidException) {
            throw new AuthenticationException('Token is not yet valid.');
        } catch (\Throwable) {
            // Malformed segments, bad JSON, unsupported algorithm, etc.
            throw new AuthenticationException('Token is malformed.');
        }

        if (($claims->typ ?? null) !== $expectedType) {
            throw new AuthenticationException('Token is not valid for this operation.');
        }

        if (($claims->iss ?? null) !== $this->issuer() || ($claims->aud ?? null) !== $this->audience()) {
            throw new AuthenticationException('Token was issued for a different service.');
        }

        if (empty($claims->jti) || empty($claims->sub)) {
            throw new AuthenticationException('Token is missing required claims.');
        }

        if ($this->isDenied($claims->jti)) {
            throw new AuthenticationException('Token has been revoked.');
        }

        if ($this->isBeforeEpoch($claims->sub, (int) ($claims->iat ?? 0))) {
            throw new AuthenticationException('Token has been revoked.');
        }

        return $claims;
    }

    /**
     * Revoke a single token by adding its id to the denylist until it would
     * have expired anyway. Storing it any longer would waste memory.
     */
    public function revoke(object $claims): void
    {
        $jti = $claims->jti ?? null;
        $exp = (int) ($claims->exp ?? 0);

        if (! $jti) {
            return;
        }

        $secondsRemaining = $exp - now()->timestamp;

        if ($secondsRemaining <= 0) {
            return; // Already expired; nothing to revoke.
        }

        Cache::put(self::DENYLIST_PREFIX.$jti, true, $secondsRemaining);
    }

    /**
     * Revoke every token ever issued to this user, by moving their epoch
     * forward to now. Used on "logout everywhere", password change, and
     * account deactivation.
     */
    public function revokeAllForUser(User $user): void
    {
        // Stored one second ahead of now so that every token already in
        // circulation — including any minted during this same second — fails
        // the `iat < epoch` check. Without the +1, the token the caller is
        // holding right now would survive its own "log out everywhere".
        Cache::put(
            self::EPOCH_PREFIX.$user->getKey(),
            now()->timestamp + 1,
            $this->refreshTtl()
        );
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * @return array{token: string, token_type: string, expires_in: int, expires_at: string}
     */
    private function issue(User $user, string $type, int $ttl): array
    {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addSeconds($ttl);

        $payload = [
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'sub' => (string) $user->getKey(),
            'jti' => (string) Str::uuid(),
            'iat' => $issuedAt->timestamp,
            'nbf' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
            'typ' => $type,
            // Role is a convenience for the client only. Server-side
            // authorization always re-reads the role from the database.
            'role' => $user->role?->value,
        ];

        return [
            'token' => JWT::encode($payload, $this->secret(), $this->algorithm()),
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function isDenied(string $jti): bool
    {
        return Cache::has(self::DENYLIST_PREFIX.$jti);
    }

    private function isBeforeEpoch(string $userId, int $issuedAt): bool
    {
        $epoch = Cache::get(self::EPOCH_PREFIX.$userId);

        return $epoch !== null && $issuedAt < (int) $epoch;
    }

    private function secret(): string
    {
        $secret = config('auth.jwt.secret');

        if (blank($secret)) {
            throw new RuntimeException(
                'JWT_SECRET is not configured. Refusing to sign tokens with an empty key.'
            );
        }

        return $secret;
    }

    private function algorithm(): string
    {
        $algorithm = (string) config('auth.jwt.algorithm', 'HS256');

        // Pin to symmetric HMAC algorithms we actually support. This also
        // blocks an operator from accidentally configuring "none".
        if (! in_array($algorithm, ['HS256', 'HS384', 'HS512'], true)) {
            throw new RuntimeException("Unsupported JWT algorithm [{$algorithm}].");
        }

        return $algorithm;
    }

    private function issuer(): string
    {
        return (string) config('auth.jwt.issuer');
    }

    private function audience(): string
    {
        return (string) config('auth.jwt.audience');
    }

    private function accessTtl(): int
    {
        return (int) config('auth.jwt.expiration', 3600);
    }

    private function refreshTtl(): int
    {
        return (int) config('auth.jwt.refresh_ttl', 1209600);
    }

    public static function typeAccess(): string
    {
        return self::TYPE_ACCESS;
    }

    public static function typeRefresh(): string
    {
        return self::TYPE_REFRESH;
    }
}
