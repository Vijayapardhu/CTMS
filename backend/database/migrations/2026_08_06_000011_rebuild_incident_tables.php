<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidents and replacement assignments (FR-11, FR-12).
 *
 * The placeholder tables predate the three-class incident model: they had no
 * concept of a life-safety incident, no acknowledgement state, and no
 * replacement approval chain. Neither holds data and nothing writes to them,
 * so both are rebuilt rather than patched.
 *
 * Two properties matter more than the columns:
 *
 * 1. `trip_id` is nullable. A bus can catch fire in the depot. Tying every
 *    incident to a trip would make the worst ones unreportable.
 *
 * 2. Reports are immutable (BR-357). Follow-up is appended as notes; the
 *    original submission is evidence and is never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('replacement_assignments');
        Schema::dropIfExists('vehicle_incidents');

        Schema::create('vehicle_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable: an incident can happen off-trip.
            $table->foreignUuid('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->foreignUuid('bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $table->foreignUuid('driver_id')->nullable()->constrained('drivers')->nullOnDelete();

            $table->enum('incident_class', ['LIFE_SAFETY', 'OPERATIONAL', 'SERVICE']);

            $table->enum('incident_type', [
                'SOS', 'ACCIDENT', 'MEDICAL', 'SECURITY',
                'BREAKDOWN', 'FLAT_TYRE', 'ENGINE_FAULT', 'BRAKE_FAULT', 'FUEL',
                'DIVERSION', 'CONGESTION', 'WEATHER', 'PASSENGER_CONDUCT',
                // System-raised (BR-259); never accepted from a client.
                'TRACKING_LOST',
            ]);

            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);

            $table->enum('status', [
                'REPORTED', 'ACKNOWLEDGED', 'IN_PROGRESS', 'ESCALATED', 'RESOLVED', 'CLOSED',
            ])->default('REPORTED');

            $table->text('description');

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Stored outside the web root, served through an authorising
            // check (BR-367).
            $table->string('photo_path')->nullable();

            $table->unsignedInteger('passengers_aboard')->default(0);
            $table->boolean('vehicle_can_continue')->default(false);

            $table->foreignUuid('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at');

            // Distinct from resolution: "someone has seen this" is its own fact.
            $table->foreignUuid('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->foreignUuid('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamp('escalated_at')->nullable();

            // A cancelled SOS is recorded, never erased (BR-355).
            $table->boolean('was_cancelled')->default(false);
            $table->text('cancellation_note')->nullable();

            $table->foreignUuid('maintenance_ticket_id')
                ->nullable()->constrained('maintenance_tickets')->nullOnDelete();

            // Absorbs an offline replay from a driver's device.
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'incident_class']);
            $table->index('reported_at');
            $table->index('trip_id');
            $table->index('bus_id');
        });

        // Append-only follow-up. The original report is never edited.
        Schema::create('incident_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_incident_id')
                ->constrained('vehicle_incidents')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamps();

            $table->index('vehicle_incident_id');
        });

        Schema::create('replacement_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignUuid('vehicle_incident_id')
                ->nullable()->constrained('vehicle_incidents')->nullOnDelete();

            $table->foreignUuid('original_bus_id')->constrained('buses')->cascadeOnDelete();
            $table->foreignUuid('replacement_bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $table->foreignUuid('replacement_driver_id')->nullable()->constrained('drivers')->nullOnDelete();

            $table->enum('status', [
                'RECOMMENDED', 'APPROVED', 'REJECTED', 'DISPATCHED', 'ARRIVED', 'COMPLETED',
            ])->default('RECOMMENDED');

            $table->text('reason');

            /** Ranking inputs, kept so a decision can be reconstructed later. */
            $table->unsignedInteger('distance_metres')->nullable();
            $table->unsignedInteger('passengers_to_transfer')->default(0);

            $table->foreignUuid('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('arrived_at')->nullable();

            $table->timestamps();

            $table->index(['trip_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_assignments');
        Schema::dropIfExists('incident_notes');
        Schema::dropIfExists('vehicle_incidents');

        // Restore the placeholder shapes so the migration is reversible.
        Schema::create('vehicle_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained('trips')->onDelete('cascade');
            $table->foreignUuid('driver_id')->constrained('drivers')->onDelete('cascade');
            $table->foreignUuid('bus_id')->constrained('buses')->onDelete('cascade');
            $table->enum('incident_type', ['BREAKDOWN', 'ACCIDENT', 'TRAFFIC_JAM', 'MECHANICAL_ISSUE', 'STUDENT_MISCONDUCT']);
            $table->text('description');
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->foreignUuid('reported_by_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['REPORTED', 'INVESTIGATING', 'RESOLVED'])->default('REPORTED');
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('replacement_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('original_bus_id')->constrained('buses')->onDelete('cascade');
            $table->foreignUuid('replacement_bus_id')->constrained('buses')->onDelete('cascade');
            $table->foreignUuid('trip_id')->constrained('trips')->onDelete('cascade');
            $table->text('reason');
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->foreignUuid('created_by_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }
};
