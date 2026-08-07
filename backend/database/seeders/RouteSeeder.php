<?php

namespace Database\Seeders;

use App\Models\Route;
use Illuminate\Database\Seeder;

/**
 * Routes and their stops.
 *
 * Built through the factory rather than hand-written inserts. The previous
 * version wrote `geofence_radius_meters` and `estimated_time_from_start_minutes`
 * — neither column exists — so `migrate:fresh --seed` had been failing outright.
 * Going through the factory means the seeder cannot drift from the schema
 * again: the factory is exercised by the whole test suite, so a column change
 * breaks it somewhere visible rather than only at seed time.
 */
class RouteSeeder extends Seeder
{
    public function run(): void
    {
        Route::factory()
            ->count(5)
            ->withStops(6)
            ->create();
    }
}
