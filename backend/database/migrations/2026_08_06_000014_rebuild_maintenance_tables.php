<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 5 — maintenance (FR-14, BR-350, BR-358, BR-366).
 *
 * The placeholder table carried three faults that only show up in use:
 * `assigned_to_id` pointed at `users` while the model's relation read
 * `assigned_to` and expected a driver; there was nowhere to record what was
 * actually found or done; and nothing linked a ticket back to the incident or
 * inspection that raised it, so "why is this bus off the road" had no answer.
 *
 * Preventive maintenance (BG-16, BR-366) has no table at all in the
 * placeholder schema, so it is added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            // BR-061 — the fleet's running total. Every reading that arrives
            // (inspection, trip close, workshop) is checked against this and
            // may only move it forward. Without a stored high-water mark there
            // is nothing for "monotonic" to be measured against.
            $table->unsignedInteger('current_odometer')->nullable()->after('mileage');
        });

        Schema::table('maintenance_tickets', function (Blueprint $table) {
            // What raised it. Nullable because a ticket may also be opened by
            // hand, or by the preventive scan.
            $table->foreignUuid('vehicle_incident_id')->nullable()->after('bus_id')
                ->constrained('vehicle_incidents')->nullOnDelete();
            $table->foreignUuid('vehicle_inspection_id')->nullable()->after('vehicle_incident_id')
                ->constrained('vehicle_inspections')->nullOnDelete();

            $table->foreignUuid('opened_by_id')->nullable()->after('vehicle_inspection_id')
                ->constrained('users')->nullOnDelete();

            // BR-358 — closing a ticket is what lets a bus back on the road,
            // so who did it is a safety record, not metadata.
            $table->foreignUuid('completed_by_id')->nullable()->after('assigned_to_id')
                ->constrained('users')->nullOnDelete();

            $table->text('resolution_notes')->nullable()->after('parts_used');
            $table->text('cancellation_reason')->nullable()->after('resolution_notes');

            // Odometer at the time of work, for the preventive schedule.
            $table->unsignedInteger('odometer_reading')->nullable()->after('cancellation_reason');

            $table->timestamp('started_at')->nullable()->after('odometer_reading');

            $table->index(['bus_id', 'status']);
            $table->index(['status', 'priority']);
        });

        Schema::create('preventive_maintenance_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bus_id')->constrained('buses')->cascadeOnDelete();

            $table->string('service_name');
            $table->text('description')->nullable();

            // A service falls due on whichever comes first — time or distance.
            // A bus doing 400km a day and one doing 40 do not need the same
            // interval, and neither rule alone is safe on its own.
            $table->unsignedInteger('interval_days')->nullable();
            $table->unsignedInteger('interval_km')->nullable();

            $table->date('last_serviced_on')->nullable();
            $table->unsignedInteger('last_serviced_odometer')->nullable();

            $table->date('due_on')->nullable();
            $table->unsignedInteger('due_at_odometer')->nullable();

            // BR-366 — how far past due a bus may still be assigned before
            // the block bites. Per-schedule, because a brake service and a
            // cabin filter do not deserve the same grace.
            $table->unsignedInteger('grace_days')->default(7);

            $table->boolean('is_active')->default(true);

            $table->foreignUuid('open_ticket_id')->nullable()
                ->constrained('maintenance_tickets')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bus_id', 'is_active']);
            $table->index('due_on');
            // Looked up on every ticket completion to roll the schedule
            // forward. A foreign key is not an index in PostgreSQL.
            $table->index('open_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_maintenance_schedules');

        Schema::table('buses', function (Blueprint $table) {
            $table->dropColumn('current_odometer');
        });

        Schema::table('maintenance_tickets', function (Blueprint $table) {
            $table->dropIndex(['bus_id', 'status']);
            $table->dropIndex(['status', 'priority']);

            $table->dropConstrainedForeignId('vehicle_incident_id');
            $table->dropConstrainedForeignId('vehicle_inspection_id');
            $table->dropConstrainedForeignId('opened_by_id');
            $table->dropConstrainedForeignId('completed_by_id');

            $table->dropColumn([
                'resolution_notes', 'cancellation_reason', 'odometer_reading', 'started_at',
            ]);
        });
    }
};
