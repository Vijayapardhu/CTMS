<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text `vehicle_registration` link with a real foreign key.
 *
 * The unique index is the point: it makes "one driver per bus" a guarantee the
 * database enforces, so two concurrent assignment requests cannot both succeed.
 * Application-level checks alone lose that race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->foreignUuid('assigned_bus_id')
                ->nullable()
                ->after('license_expiry_date')
                ->constrained('buses')
                // Retiring a bus releases its driver rather than deleting them.
                ->nullOnDelete();

            $table->unique('assigned_bus_id');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropUnique(['assigned_bus_id']);
            $table->dropConstrainedForeignId('assigned_bus_id');
        });
    }
};
