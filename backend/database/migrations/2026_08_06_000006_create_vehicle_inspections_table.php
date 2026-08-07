<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-trip vehicle inspections (BR-107, BR-108).
 *
 * A driver may not start a trip without a passed inspection for that bus on
 * that day. This is the last point at which a fault can be caught while there
 * is still time to substitute a vehicle.
 *
 * Inspections are immutable once submitted, for the same reason incident
 * reports are: they are evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bus_id')->constrained('buses')->cascadeOnDelete();
            $table->foreignUuid('driver_id')->constrained('drivers')->cascadeOnDelete();

            $table->date('inspected_on');
            $table->timestamp('inspected_at');

            $table->enum('outcome', ['PASSED', 'PASSED_WITH_DEFECTS', 'FAILED']);

            $table->unsignedInteger('odometer_reading');
            $table->text('notes')->nullable();

            // Opened automatically when any item fails (BR-108).
            $table->foreignUuid('maintenance_ticket_id')
                ->nullable()
                ->constrained('maintenance_tickets')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['bus_id', 'inspected_on']);
            $table->index(['driver_id', 'inspected_on']);
            $table->index('outcome');
        });

        Schema::create('vehicle_inspection_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_inspection_id')
                ->constrained('vehicle_inspections')
                ->cascadeOnDelete();

            $table->enum('item', [
                'BRAKES', 'TYRES', 'LIGHTS', 'STEERING', 'DOORS', 'EMERGENCY_EXIT',
                'FIRE_EXTINGUISHER', 'FIRST_AID_KIT', 'MIRRORS', 'HORN', 'WIPERS',
                'FUEL_LEVEL', 'FLUID_LEVELS', 'CLEANLINESS',
            ]);

            $table->boolean('passed');
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();

            // One verdict per item per inspection.
            $table->unique(['vehicle_inspection_id', 'item'], 'inspection_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspection_items');
        Schema::dropIfExists('vehicle_inspections');
    }
};
