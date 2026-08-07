<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\AuthenticationException;
use App\Exceptions\AuthorizationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route to a set of roles, e.g. `role:ADMIN` or `role:ADMIN,DRIVER`.
 *
 * This is a coarse first filter. Record-level ownership ("is this the driver's
 * own trip?") is decided by policies, not here.
 */
class RoleAuthorize
{
    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            // No identity at all is a 401, not a 403 — the client should
            // authenticate rather than give up.
            throw new AuthenticationException('Authentication is required.');
        }

        if ($roles === []) {
            // Deny by default: a role gate with no roles is a configuration
            // mistake, and must never be read as "allow everyone".
            throw new AuthorizationException('This resource is not accessible.');
        }

        $allowed = array_map(
            fn (string $role) => UserRole::tryFrom($role)
                ?? throw new \InvalidArgumentException("Unknown role [{$role}] in route definition."),
            $roles
        );

        if (! $user->hasAnyRole(...$allowed)) {
            throw new AuthorizationException('You do not have permission to access this resource.');
        }

        return $next($request);
    }
}
