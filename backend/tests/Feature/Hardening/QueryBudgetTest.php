<?php

namespace Tests\Feature\Hardening;

use App\Enums\MaintenancePriority;
use App\Models\Bus;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * N+1 guards on the list endpoints.
 *
 * Inspecting a controller for missing eager loads finds the obvious cases and
 * misses the ones that come from an accessor or a resource. Counting the
 * queries finds all of them, and keeps finding them after somebody adds a
 * relation to a response next year.
 *
 * The budgets below are deliberately loose — they are there to catch a list
 * that scales with row count, not to police a query or two.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run a request and return how many queries it took.
     */
    private function queriesFor(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $request();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    /**
     * Assert that a list endpoint costs about the same for many rows as for
     * few. That difference is what an N+1 actually is.
     */
    private function assertDoesNotScale(string $url, callable $seedOne, User $actor): void
    {
        $seedOne();

        $small = $this->queriesFor(fn () => $this->getJson($url, $this->authHeader($actor))->assertOk());

        for ($i = 0; $i < 9; $i++) {
            $seedOne();
        }

        $large = $this->queriesFor(fn () => $this->getJson($url, $this->authHeader($actor))->assertOk());

        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "{$url} ran {$small} queries for 1 row and {$large} for 10 — it scales with row count."
        );
    }

    #[Test]
    public function listing_buses_does_not_scale_with_the_fleet(): void
    {
        $admin = $this->createAdmin();

        $this->assertDoesNotScale(
            '/api/v1/buses',
            fn () => Bus::factory()->create(),
            $admin,
        );
    }

    #[Test]
    public function listing_routes_does_not_scale(): void
    {
        $admin = $this->createAdmin();

        $this->assertDoesNotScale(
            '/api/v1/routes',
            fn () => Route::factory()->withStops(3)->create(),
            $admin,
        );
    }

    #[Test]
    public function listing_users_does_not_scale(): void
    {
        $admin = $this->createAdmin();

        $this->assertDoesNotScale(
            '/api/v1/users',
            fn () => $this->createStudent(),
            $admin,
        );
    }

    #[Test]
    public function listing_trips_does_not_scale(): void
    {
        $admin = $this->createAdmin();

        $this->assertDoesNotScale(
            '/api/v1/trips',
            fn () => Trip::factory()->departingNow()->create([
                'route_id' => Route::factory()->withStops(2)->create()->id,
            ]),
            $admin,
        );
    }

    #[Test]
    public function listing_maintenance_tickets_does_not_scale(): void
    {
        $admin = $this->createAdmin();
        $maintenance = app(MaintenanceService::class);

        // This one carries four relations in its response, so it is the most
        // likely of the lot to regress.
        $this->assertDoesNotScale(
            '/api/v1/maintenance-tickets',
            fn () => $maintenance->open(Bus::factory()->create(), [
                'issue_description' => 'Routine fault for the query budget test.',
                'priority' => MaintenancePriority::LOW,
            ], $admin),
            $admin,
        );
    }

    #[Test]
    public function listing_the_audit_trail_does_not_scale(): void
    {
        $admin = $this->createSuperAdmin();

        $this->assertDoesNotScale(
            '/api/v1/audit-logs',
            fn () => Bus::factory()->create(),
            $admin,
        );
    }
}
