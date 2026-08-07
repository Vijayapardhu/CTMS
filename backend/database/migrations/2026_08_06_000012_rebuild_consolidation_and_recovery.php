<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4D — trip consolidation (FR-13) and trip recovery.
 *
 * The placeholder `bus_merge_recommendations` table modelled a merge as a pair
 * of *buses*. It is not: two buses are not mergeable, two **trips** on a given
 * day are. A bus-to-bus row cannot answer "which passengers move", "where do
 * the routes diverge" or "has the source already gone past that point" — the
 * three questions BR-362 and BR-364 turn on. The table is rebuilt around the
 * trip pair, and the old shape is restored on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bus_merge_recommendations');

        Schema::create('trip_consolidations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The trip that is stood down, and the one that absorbs it.
            $table->foreignUuid('source_trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignUuid('target_trip_id')->constrained('trips')->cascadeOnDelete();

            $table->enum('status', [
                'PROPOSED', 'APPROVED', 'EXECUTED', 'REJECTED', 'EXPIRED',
            ])->default('PROPOSED');

            $table->text('reason');

            // Captured at proposal time so the decision is reviewable against
            // what was true when it was made, not what is true now.
            $table->unsignedInteger('source_passengers');
            $table->unsignedInteger('target_passengers');
            $table->unsignedInteger('target_capacity');
            $table->decimal('estimated_savings', 10, 2)->nullable();

            // BR-364 — the last stop the two routes have in common. Past this
            // point the target bus cannot serve the source's passengers.
            $table->foreignUuid('divergence_stop_id')->nullable()
                ->constrained('route_stops')->nullOnDelete();
            $table->unsignedInteger('divergence_sequence')->nullable();

            $table->foreignUuid('proposed_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // BR-363 — passengers are told before the merge takes effect, so
            // the notification timestamp is a precondition of execution, not
            // a record of it.
            $table->timestamp('passengers_notified_at')->nullable();
            $table->timestamp('executed_at')->nullable();

            // BG-11 — a proposal nobody decided must not execute tomorrow on
            // yesterday's occupancy figures.
            $table->timestamp('expires_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at']);
            $table->index('source_trip_id');
            $table->index('target_trip_id');
        });

        Schema::table('trips', function (Blueprint $table) {
            // Recovery: a trip stood down by a merge points at the trip that
            // took its passengers, so a rider following the old trip can be
            // redirected rather than left staring at a cancelled journey.
            $table->foreignUuid('merged_into_trip_id')->nullable()->after('schedule_id')
                ->constrained('trips')->nullOnDelete();

            // BR-258 — a driver swapped mid-route is a fact about the trip,
            // not an overwrite of who started it.
            $table->foreignUuid('original_driver_id')->nullable()->after('driver_id')
                ->constrained('drivers')->nullOnDelete();
        });

        Schema::create('trip_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();

            // BR-258 — corrections after close are new attributed records that
            // preserve the original. The original value lives here forever.
            $table->string('field');
            $table->text('original_value')->nullable();
            $table->text('corrected_value')->nullable();
            $table->text('reason');

            $table->foreignUuid('corrected_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['trip_id', 'created_at']);
        });

        Schema::create('attendance_discrepancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();

            // BR-266 — a headcount that disagrees with boarding events is
            // recorded as a discrepancy, never reconciled away. Both numbers
            // are kept; neither is allowed to overwrite the other.
            $table->unsignedInteger('headcount');
            $table->unsignedInteger('boarding_event_count');
            $table->integer('difference');

            $table->enum('status', ['OPEN', 'REVIEWED'])->default('OPEN');
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewed_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->unique('trip_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_discrepancies');
        Schema::dropIfExists('trip_corrections');

        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_trip_id');
            $table->dropConstrainedForeignId('original_driver_id');
        });

        Schema::dropIfExists('trip_consolidations');

        // Restore the placeholder shape so the rollback is honest.
        Schema::create('bus_merge_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bus1_id')->constrained('buses')->onDelete('cascade');
            $table->foreignUuid('bus2_id')->constrained('buses')->onDelete('cascade');
            $table->text('reason');
            $table->decimal('estimated_savings', 10, 2)->nullable();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'IMPLEMENTED'])->default('PENDING');
            $table->foreignUuid('recommended_by_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('decision_by_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('decision_date')->nullable();
            $table->timestamps();

            $table->index('bus1_id');
            $table->index('bus2_id');
            $table->index('status');
        });
    }
};
