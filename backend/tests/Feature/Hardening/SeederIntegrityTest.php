<?php

namespace Tests\Feature\Hardening;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\BusSeeder;
use Database\Seeders\DriverSeeder;
use Database\Seeders\RouteSeeder;
use Database\Seeders\StudentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seeders have to actually run.
 *
 * Nothing else in the suite exercised them, and `RouteSeeder` had been writing
 * two columns that do not exist — so `migrate:fresh --seed` failed outright on
 * a clean checkout, which is the first command anyone runs. Seeders drift
 * because they duplicate what factories already know; these tests make the
 * drift fail somewhere visible.
 */
class SeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_seeder_runs(): void
    {
        $this->seed([
            AdminSeeder::class,
            BusSeeder::class,
            RouteSeeder::class,
            DriverSeeder::class,
            StudentSeeder::class,
        ]);

        foreach (['users', 'buses', 'routes', 'route_stops', 'drivers', 'students'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} was left empty by the seeders.");
        }
    }

    #[Test]
    public function seeded_route_stops_satisfy_their_constraints(): void
    {
        $this->seed(RouteSeeder::class);

        $stops = DB::table('route_stops')->get();

        foreach ($stops as $stop) {
            // Every one of these is NOT NULL in the schema. A seeder that
            // omits one only fails on the database that enforces it.
            $this->assertNotNull($stop->address);
            $this->assertNotNull($stop->distance_from_start_km);
            $this->assertNotNull($stop->estimated_arrival_minutes);
            $this->assertNotNull($stop->stop_type);

            $this->assertGreaterThanOrEqual(-90, (float) $stop->latitude);
            $this->assertLessThanOrEqual(90, (float) $stop->latitude);
        }
    }

    #[Test]
    public function seeded_routes_report_their_real_stop_count(): void
    {
        $this->seed(RouteSeeder::class);

        foreach (DB::table('routes')->get() as $route) {
            $actual = DB::table('route_stops')->where('route_id', $route->id)->count();

            // A denormalised counter that disagrees with the rows is worse
            // than no counter, because screens trust it.
            $this->assertSame((int) $route->number_of_stops, $actual);
        }
    }

    #[Test]
    public function seeded_enum_columns_use_canonical_casing(): void
    {
        $this->seed([AdminSeeder::class, BusSeeder::class, DriverSeeder::class, StudentSeeder::class]);

        foreach ([
            'users' => 'role',
            'buses' => 'status',
            'drivers' => 'status',
            'students' => 'status',
        ] as $table => $column) {
            foreach (DB::table($table)->pluck($column) as $value) {
                if ($value === null) {
                    continue;
                }

                // Lowercase enum values were the original defect in this
                // codebase; a seeder is a quiet way to reintroduce them.
                $this->assertSame(
                    strtoupper((string) $value),
                    (string) $value,
                    "{$table}.{$column} was seeded as '{$value}', which is not canonical.",
                );
            }
        }
    }

    #[Test]
    public function the_system_actor_survives_a_fresh_seed(): void
    {
        $this->seed(AdminSeeder::class);

        // Created by migration rather than by a seeder, so a fresh database
        // has it before any seeder runs — and the scheduler has an identity
        // even on a deployment nobody has seeded.
        $this->assertNotNull(User::systemActor());
    }
}
