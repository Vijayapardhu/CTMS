<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthenticationException;
use App\Models\User;
use App\Services\Auth\TokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the caller's identity from a Bearer JWT.
 *
 * The token is only a claim of identity. The user is always re-loaded from the
 * database and re-checked for active status, so revoking or deactivating an
 * account takes effect on the very next request rather than at token expiry.
 */
class AuthenticateRequest
{
    /** Request attribute holding the decoded JWT claims. */
    public const CLAIMS_ATTRIBUTE = 'jwt_claims';

    public function __construct(private readonly TokenService $tokens) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            throw new AuthenticationException('Authentication token is missing.');
        }

        // Throws AuthenticationException on bad signature, expiry, wrong type,
        // wrong audience, revoked jti, or a stale token epoch.
        $claims = $this->tokens->parse($token, TokenService::typeAccess());

        $user = User::find($claims->sub);

        if (! $user) {
            throw new AuthenticationException('The account for this token no longer exists.');
        }

        if (! $user->is_active) {
            throw new AuthenticationException('This account has been deactivated.');
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set(self::CLAIMS_ATTRIBUTE, $claims);

        // Bind the identity into the auth system as well, so Gate, policies
        // and `auth()->user()` all resolve the same user as `$request->user()`.
        // Without this, `$this->authorize()` in a controller would silently
        // see a guest and deny (or, worse, evaluate a policy against null).
        Auth::setUser($user);

        return $next($request);
    }
}
