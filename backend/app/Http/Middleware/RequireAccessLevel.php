<?php

namespace App\Http\Middleware;

use App\Enums\AccessLevel;
use App\Exceptions\AuthenticationException;
use App\Exceptions\AuthorizationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second axis of administrator authorization, e.g. `access:OPERATIONS`.
 *
 * `admins.access_level` has existed since the first migration. It was stored,
 * accepted at registration and returned to the client — and read by nothing.
 * Every policy asked only `isAdmin()`, so an account created as `VIEWER` could
 * approve replacement buses, close incidents, publish announcements to the
 * whole fleet and export a student's file. The API told the client they were a
 * viewer and then let them do everything.
 *
 * The ladder, and what each tier is for:
 *
 *   VIEWER      read-only oversight
 *   SUPPORT     day-to-day operations — acknowledge incidents, review
 *               attendance disputes, dispatch what somebody else approved
 *   OPERATIONS  decisions that cost money or move vehicles — approve
 *               replacements, merge services, manage the fleet and network
 *   SUPER_ADMIN the system itself — accounts, access levels, personal-data
 *               export, the audit trail
 *
 * A supervisor is SUPPORT; a transport head is OPERATIONS or SUPER_ADMIN. No
 * new roles and no new endpoints were needed for either.
 */
class RequireAccessLevel
{
    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException|AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$levels): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException('Authentication is required.');
        }

        if ($levels === []) {
            // Deny by default: a gate with no level is a configuration
            // mistake, never "allow everyone".
            throw new AuthorizationException('This resource is not accessible.');
        }

        $required = AccessLevel::tryFrom(strtoupper($levels[0]))
            ?? throw new \InvalidArgumentException("Unknown access level [{$levels[0]}] in route definition.");

        $held = $user->accessLevel();

        if ($held === null || ! $held->atLeast($required)) {
            throw new AuthorizationException(
                "This action requires {$required->value} access. Your account holds "
                .($held?->value ?? 'none').'.',
            );
        }

        return $next($request);
    }
}
