<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Continuous-state tracking (FR-07, FR-08, FR-09).
 *
 * Three changes:
 *
 * 1. `trip_stop_progress` — a geofence state machine per stop per trip.
 *    Arrival is a *transition*, not a single GPS point: one stray reading
 *    inside the radius must not fire "your bus is here" while the bus is
 *    still two streets away.
 *
 * 2. Idempotency keys on positions and boarding events. A driver's device
 *    queues these offline and replays them on reconnect; without a key, a
 *    retried sync counts the same student twice.
 *
 * 3. Device timestamps alongside server timestamps. The device clock is
 *    untrusted but recorded — reconstructing what a driver saw requires
 *    knowing what their device believed at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stop_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignUuid('route_stop_id')->constrained('route_stops')->cascadeOnDelete();

            $table->unsignedInteger('sequence_number');

            $table->enum('state', ['PENDING', 'APPROACHING', 'ARRIVED', 'DEPARTED', 'SKIPPED'])
                ->default('PENDING');

            /** First reading inside the geofence — not yet an arrival. */
            $table->timestamp('entered_at')->nullable();

            /** Confirmed: inside the fence long enough to be real. */
            $table->timestamp('arrived_at')->nullable();

            $table->timestamp('departed_at')->nullable();

            /** Consecutive readings inside the fence, for confirmation. */
            $table->unsignedSmallInteger('inside_readings')->default(0);

            $table->timestamp('eta_at')->nullable();
            $table->unsignedInteger('boarded_count')->default(0);
            $table->unsignedInteger('alighted_count')->default(0);
            $table->text('skip_reason')->nullable();

            $table->timestamps();

            $table->unique(['trip_id', 'route_stop_id'], 'trip_stop_progress_unique');
            $table->index(['trip_id', 'sequence_number']);
            $table->index('state');
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            // BR-307 — an offline replay must be absorbed, not re-stored.
            $table->string('idempotency_key', 64)->nullable()->after('trip_id');
            $table->timestamp('device_recorded_at')->nullable()->after('recorded_at');
            $table->boolean('clock_skew_suspected')->default(false)->after('device_recorded_at');

            $table->unique(['trip_id', 'idempotency_key'], 'trip_locations_idempotency_unique');
        });

        Schema::table('passenger_logs', function (Blueprint $table) {
            // Headcount mode records a boarding with no named student and,
            // when the driver counts between stops, no named stop either.
            // Named boarding is what makes "your child boarded" possible; both
            // are supported, and which one is used is an institutional decision.
            $table->foreignUuid('student_id')->nullable()->change();
            $table->foreignUuid('route_stop_id')->nullable()->change();

            $table->foreignUuid('recorded_by_id')->nullable()->after('student_id')
                ->constrained('users')->nullOnDelete();

            $table->string('idempotency_key', 64)->nullable()->after('recorded_by_id');

            $table->unique(['trip_id', 'idempotency_key'], 'passenger_logs_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('passenger_logs', function (Blueprint $table) {
            $table->dropUnique('passenger_logs_idempotency_unique');
            $table->dropConstrainedForeignId('recorded_by_id');
            $table->dropColumn('idempotency_key');
            $table->foreignUuid('student_id')->nullable(false)->change();
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->dropUnique('trip_locations_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'device_recorded_at', 'clock_skew_suspected']);
        });

        Schema::dropIfExists('trip_stop_progress');
    }
};
