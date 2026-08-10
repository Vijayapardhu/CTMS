<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far the bus still has to drive to each remaining stop (FR-09).
 *
 * The Route Matrix already returns this alongside the duration that becomes
 * `eta_at` — it was simply being thrown away, which left the driver's handset
 * with nothing to show but a straight line drawn across the fields. On the
 * Velangi run that is 24.9 km against a real road distance of 37 km, and a
 * driver reading the smaller number plans the wrong afternoon.
 *
 * Stored rather than recomputed on read: the distance that produced the ETA
 * and the ETA itself must agree, and asking Google again from a bus that has
 * moved fifty metres would spend quota to produce a slightly different answer.
 *
 * `distance_is_estimate` is not decoration. It is the difference between a
 * road distance and straight-line arithmetic with a fudge factor, and the
 * handset is required to show which one it is holding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_stop_progress', function (Blueprint $table) {
            // Nullable: a trip that has not reported a position yet has no
            // distance, and zero would be a lie about a bus at the depot.
            $table->unsignedInteger('distance_metres')->nullable()->after('eta_at');
            $table->boolean('distance_is_estimate')->nullable()->after('distance_metres');
        });
    }

    public function down(): void
    {
        Schema::table('trip_stop_progress', function (Blueprint $table) {
            $table->dropColumn(['distance_metres', 'distance_is_estimate']);
        });
    }
};
