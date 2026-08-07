<?php

namespace Tests\Feature\Hardening;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deny by default, verified route by route.
 *
 * "Every route is authenticated unless explicitly public" is the sort of rule
 * that is true on the day it is written and quietly false a month later, when
 * somebody adds an endpoint outside the middleware group. Walking the router
 * is the only way to keep it true.
 */
class RouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The only endpoints allowed to answer without a token.
     *
     * Anything added here is a deliberate decision that has to survive review.
     *
     * @var array<int, string>
     */
    private const PUBLIC_ROUTES = [
        'api/v1/auth/login',
        'api/v1/auth/register',
        'api/v1/auth/refresh',
        'api/health',
        'api/v1/health',
        'up',
    ];

    /**
     * @return array<int, Route>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn ($route) => str_starts_with($route->uri(), 'api/'),
        ));
    }

    #[Test]
    public function the_router_actually_has_routes(): void
    {
        // Without this the sweep below passes on an empty list.
        $this->assertGreaterThan(100, count($this->apiRoutes()));
    }

    #[Test]
    public function every_api_route_is_authenticated_unless_explicitly_public(): void
    {
        $unguarded = [];

        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->uri(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $authenticated = array_filter(
                $middleware,
                fn ($m) => is_string($m) && str_contains($m, 'auth'),
            );

            if ($authenticated === []) {
                $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "These routes have no authentication middleware:\n  ".implode("\n  ", $unguarded),
        );
    }

    #[Test]
    public function no_route_uses_a_middleware_that_does_not_match_the_token_scheme(): void
    {
        $wrong = [];

        foreach ($this->apiRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                // The system issues its own JWTs. A Sanctum guard on a
                // JWT-authenticated route rejects every valid token, which is
                // exactly the defect this codebase started with.
                if (is_string($middleware) && str_contains($middleware, 'auth:sanctum')) {
                    $wrong[] = $route->uri();
                }
            }
        }

        $this->assertSame([], $wrong, 'auth:sanctum found on: '.implode(', ', $wrong));
    }

    #[Test]
    public function every_write_route_is_throttled(): void
    {
        $unthrottled = [];

        foreach ($this->apiRoutes() as $route) {
            $methods = array_diff($route->methods(), ['HEAD']);

            if (! array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                continue;
            }

            $throttled = array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_contains($m, 'throttle'),
            );

            if ($throttled === []) {
                $unthrottled[] = implode('|', $methods).' '.$route->uri();
            }
        }

        // Incident reporting is deliberately excluded from tight throttling —
        // a system that rate-limits an emergency has failed — but it still
        // sits behind the global API limiter, so it should appear here as
        // throttled rather than absent.
        $this->assertSame(
            [],
            $unthrottled,
            "These write routes have no throttle:\n  ".implode("\n  ", $unthrottled),
        );
    }

    #[Test]
    public function an_unauthenticated_request_to_a_protected_route_is_401(): void
    {
        foreach ([
            '/api/v1/buses',
            '/api/v1/trips',
            '/api/v1/incidents',
            '/api/v1/maintenance-tickets',
            '/api/v1/consolidations',
            '/api/v1/reports/trips',
            '/api/v1/audit-logs',
            '/api/v1/geo/status',
            '/api/v1/attendance-discrepancies',
            '/api/v1/preventive-maintenance',
            '/api/v1/retention-runs',
        ] as $url) {
            $this->getJson($url)->assertStatus(401, "{$url} answered without a token.");
        }
    }

    #[Test]
    public function every_scheduled_job_is_registered(): void
    {
        $events = app(Schedule::class)->events();

        $summaries = array_map(fn ($event) => $event->getSummaryForDisplay(), $events);
        $joined = implode(' ', $summaries);

        foreach ([
            'GenerateDailyTrips',
            'ScanExpiringDocuments',
            'CloseOverdueTrips',
            'EscalateUnacknowledgedIncidents',
            'DetectStalledTrips',
            'ScanPreventiveMaintenance',
            'ProposeConsolidations',
            'PurgeExpiredData',
            'ReconcileAttendance',
        ] as $job) {
            $this->assertStringContainsString(
                $job,
                $joined,
                "{$job} is not on the schedule — it will never run in production.",
            );
        }
    }

    #[Test]
    public function every_scheduled_job_is_protected_against_overlap(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            // Two copies of the trip generator running at once produce two
            // days of trips, and two copies of the purge race each other.
            $this->assertNotNull(
                $event->withoutOverlapping ?? null,
                $event->getSummaryForDisplay().' can overlap with itself.',
            );
        }
    }
}
