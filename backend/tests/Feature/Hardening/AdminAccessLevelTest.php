<?php

namespace Tests\Feature\Hardening;

use App\Enums\AccessLevel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G3-1 — the second axis of administrator authorization, on every mutation.
 *
 * `admins.access_level` is enforced by the `access:` middleware and by nothing
 * else: every policy asks only `isAdmin()`. A mutating route that forgot the
 * middleware therefore admitted a VIEWER, and ten of them had. Two of those
 * rewrote attendance records — the evidence of what a driver actually did,
 * which BR-258 exists to protect.
 *
 * These tests are written against the *route table*, not against a list typed
 * out by hand, so a new mutation added without a level gate fails here rather
 * than being discovered by whoever it lets through.
 */
class AdminAccessLevelTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '11111111-1111-1111-1111-111111111111';

    /**
     * The ten routes and the level each demands.
     *
     * @return array<string, array{0: string, 1: string, 2: AccessLevel}>
     */
    public static function gatedMutations(): array
    {
        $id = self::UUID;

        return [
            'trip correction' => ['POST', "/api/v1/trips/{$id}/corrections", AccessLevel::OPERATIONS],
            'consolidation create' => ['POST', '/api/v1/consolidations', AccessLevel::OPERATIONS],
            'consolidation approve' => ['POST', "/api/v1/consolidations/{$id}/approve", AccessLevel::OPERATIONS],
            'consolidation reject' => ['POST', "/api/v1/consolidations/{$id}/reject", AccessLevel::OPERATIONS],
            'consolidation notify' => ['POST', "/api/v1/consolidations/{$id}/notify", AccessLevel::OPERATIONS],
            'consolidation execute' => ['POST', "/api/v1/consolidations/{$id}/execute", AccessLevel::OPERATIONS],
            'preventive maintenance create' => ['POST', '/api/v1/preventive-maintenance', AccessLevel::OPERATIONS],
            'preventive maintenance delete' => ['DELETE', "/api/v1/preventive-maintenance/{$id}", AccessLevel::OPERATIONS],
            'attendance dispute review' => ['POST', "/api/v1/attendance-discrepancies/{$id}/review", AccessLevel::SUPPORT],
            'notification resend' => ['POST', "/api/v1/notification-log/{$id}/resend", AccessLevel::SUPPORT],
        ];
    }

    // ====================================================================
    // VIEWER REACHES NONE OF THEM
    // ====================================================================

    #[Test]
    #[DataProvider('gatedMutations')]
    public function a_viewer_is_refused_every_gated_mutation(
        string $method,
        string $path,
        AccessLevel $required,
    ): void {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        $this->json($method, $path, [], $this->authHeader($viewer))
            ->assertStatus(403);
    }

    #[Test]
    public function a_viewer_is_refused_before_anything_is_written(): void
    {
        $viewer = $this->createAdminAt(AccessLevel::VIEWER);

        $this->postJson('/api/v1/preventive-maintenance', [
            'bus_id' => self::UUID,
            'interval_km' => 5000,
        ], $this->authHeader($viewer))->assertStatus(403);

        // Authorization runs in middleware, so the request never reaches the
        // service. A 403 that arrives after a partial write is not a refusal.
        $this->assertDatabaseCount('preventive_maintenance_schedules', 0);
    }

    // ====================================================================
    // SUPPORT REACHES EXACTLY TWO
    // ====================================================================

    #[Test]
    #[DataProvider('gatedMutations')]
    public function support_passes_only_the_support_gates(
        string $method,
        string $path,
        AccessLevel $required,
    ): void {
        $supervisor = $this->createAdminAt(AccessLevel::SUPPORT);

        $response = $this->json($method, $path, [], $this->authHeader($supervisor));

        if ($required === AccessLevel::SUPPORT) {
            // Past the gate. What stops it now is the missing record or the
            // empty payload, which is the controller's business, not the
            // middleware's.
            $this->assertNotSame(403, $response->status(),
                "SUPPORT should pass the gate on {$path}");
        } else {
            $response->assertStatus(403);
        }
    }

    // ====================================================================
    // OPERATIONS REACHES ALL OF THEM
    // ====================================================================

    #[Test]
    #[DataProvider('gatedMutations')]
    public function operations_passes_every_gate(
        string $method,
        string $path,
        AccessLevel $required,
    ): void {
        $head = $this->createAdminAt(AccessLevel::OPERATIONS);

        // OPERATIONS satisfies a SUPPORT gate too — `atLeast()` is a ladder,
        // not a set of equals.
        $this->assertNotSame(
            403,
            $this->json($method, $path, [], $this->authHeader($head))->status(),
            "OPERATIONS should pass the gate on {$path}",
        );
    }

    #[Test]
    #[DataProvider('gatedMutations')]
    public function a_super_admin_passes_every_gate(
        string $method,
        string $path,
        AccessLevel $required,
    ): void {
        $root = $this->createSuperAdmin();

        $this->assertNotSame(
            403,
            $this->json($method, $path, [], $this->authHeader($root))->status(),
            "SUPER_ADMIN should pass the gate on {$path}",
        );
    }

    // ====================================================================
    // THE EDGES
    // ====================================================================

    #[Test]
    public function an_admin_without_an_access_level_cannot_exist(): void
    {
        // The defensive null branch in User::accessLevel() is unreachable by
        // data: the column refuses it. Asserted rather than assumed, because
        // "what happens with no level" is exactly the question this class
        // exists to answer, and the answer is that the state cannot occur.
        $this->expectException(QueryException::class);

        $this->createAdmin([], ['access_level' => null]);
    }

    #[Test]
    public function the_least_privileged_level_still_reaches_nothing(): void
    {
        // The level a new administrator is created with, per RegistrationTest.
        $fresh = $this->createAdminAt(AccessLevel::VIEWER);

        foreach (self::gatedMutations() as [$method, $path]) {
            $this->json($method, $path, [], $this->authHeader($fresh))
                ->assertStatus(403);
        }
    }

    #[Test]
    public function a_driver_is_refused_by_role_before_level_is_considered(): void
    {
        $driver = $this->createDriver();

        foreach (self::gatedMutations() as [$method, $path]) {
            $this->json($method, $path, [], $this->authHeader($driver))
                ->assertStatus(403);
        }
    }

    #[Test]
    public function an_unauthenticated_request_is_401_not_403(): void
    {
        foreach (self::gatedMutations() as [$method, $path]) {
            $this->json($method, $path)->assertStatus(401);
        }
    }

    // ====================================================================
    // THE RULE ITSELF
    // ====================================================================

    #[Test]
    public function every_admin_only_mutation_carries_an_access_level(): void
    {
        $ungated = [];

        foreach (app('router')->getRoutes() as $route) {
            $methods = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

            if ($methods === [] || ! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $roleGated = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_contains($m, 'RoleAuthorize:ADMIN')
            );

            $levelGated = collect($middleware)->contains(
                fn ($m) => is_string($m) && str_contains($m, 'RequireAccessLevel:')
            );

            if ($roleGated && ! $levelGated) {
                $ungated[] = implode('|', $methods).' '.$route->uri();
            }
        }

        $this->assertSame([], $ungated,
            "These admin-only mutations admit every access level, including VIEWER:\n".
            implode("\n", $ungated));
    }
}
