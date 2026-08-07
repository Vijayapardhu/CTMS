<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ad-hoc trip has no schedule (AD-65).
 *
 * A field visit or an extra evening run is a real trip that was never on the
 * timetable, and the original NOT NULL made it impossible to record one.
 *
 * The (schedule_id, trip_date) unique index that guarantees idempotent
 * generation (BR-263) is unaffected: both PostgreSQL and SQLite treat NULLs as
 * distinct in a unique index, so any number of ad-hoc trips may share a date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignUuid('schedule_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignUuid('schedule_id')->nullable(false)->change();
        });
    }
};
