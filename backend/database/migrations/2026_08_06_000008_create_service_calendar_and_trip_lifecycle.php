<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service calendar and trip lifecycle accountability (FR-06).
 *
 * Two things trip generation cannot work without:
 *
 * 1. A calendar of days the service does not run (BR-264). Generating trips
 *    on a public holiday tells every rider their bus is coming when the depot
 *    is shut.
 *
 * 2. Attribution on the trip itself. "Who started this trip, and did it close
 *    properly or did the system give up on it?" must be answerable months
 *    later — an auto-closed trip and a driver-closed trip mean very different
 *    things in a punctuality report (BR-261).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_calendar_days', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->date('date')->unique();

            $table->enum('day_type', ['HOLIDAY', 'SUSPENSION', 'SPECIAL'])->default('HOLIDAY');

            /** Shown to riders, so it must be written for them. */
            $table->string('reason');

            $table->foreignUuid('declared_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('day_type');
        });

        Schema::table('trips', function (Blueprint $table) {
            // Who acted, for each lifecycle transition.
            $table->foreignUuid('started_by_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('ended_by_id')->nullable()->after('started_by_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by_id')->nullable()->after('ended_by_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by_id');

            /**
             * BR-261 — a trip the system closed because the driver forgot is
             * not the same as one that completed. Reports must be able to tell
             * them apart, or the punctuality figures are fiction.
             */
            $table->boolean('auto_closed')->default(false)->after('cancelled_at');

            /** Set when generation created it; null for an ad-hoc trip. */
            $table->timestamp('generated_at')->nullable()->after('auto_closed');

            /** Recorded when a trip is created on a non-operating day (BR-265). */
            $table->text('override_reason')->nullable()->after('generated_at');

            /**
             * BR-263 — generation is idempotent per (schedule, date). The
             * unique index is what makes that a guarantee rather than an
             * intention, so a re-run cannot double the day's trips.
             */
            $table->unique(['schedule_id', 'trip_date'], 'trips_schedule_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique('trips_schedule_date_unique');
            $table->dropConstrainedForeignId('started_by_id');
            $table->dropConstrainedForeignId('ended_by_id');
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancelled_at', 'auto_closed', 'generated_at', 'override_reason']);
        });

        Schema::dropIfExists('service_calendar_days');
    }
};
