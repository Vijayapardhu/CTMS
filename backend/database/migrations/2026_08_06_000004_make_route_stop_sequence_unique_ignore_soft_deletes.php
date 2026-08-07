<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restricts the (route_id, sequence_number) uniqueness to live stops.
 *
 * BR-200 is an invariant about the stops a route *has* — a soft-deleted stop
 * is no longer on the route. The plain unique index did not know that, so a
 * deleted stop kept occupying its position and blocked the gap-closing shift
 * that follows a deletion: removing stop 3 from a five-stop route failed with
 * a constraint violation as stop 4 tried to move into the vacated slot.
 *
 * A partial unique index expresses the rule correctly. PostgreSQL (production)
 * and SQLite (tests) both support the syntax; MySQL does not, and is not a
 * target for this project.
 */
return new class extends Migration
{
    private const INDEX = 'route_stops_route_sequence_unique';

    public function up(): void
    {
        Schema::table('route_stops', function ($table) {
            $table->dropUnique(self::INDEX);
        });

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(
                "Partial unique indexes are required for route stop sequencing and are not supported on [{$driver}]."
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.
            ' ON route_stops (route_id, sequence_number) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('route_stops', function ($table) {
            $table->unique(['route_id', 'sequence_number'], self::INDEX);
        });
    }
};
