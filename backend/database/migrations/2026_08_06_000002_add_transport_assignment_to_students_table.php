<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the transport assignment a student actually rides (FR-04).
 *
 * The API already exposed an "assign transport" endpoint, but the columns it
 * wrote to did not exist — every assignment silently failed. These are them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignUuid('route_id')
                ->nullable()
                ->after('year_of_study')
                ->constrained('routes')
                // Retiring a route must not delete the students on it; it
                // leaves them unassigned so operations can re-seat them.
                ->nullOnDelete();

            $table->foreignUuid('pickup_stop_id')
                ->nullable()
                ->after('route_id')
                ->constrained('route_stops')
                ->nullOnDelete();

            $table->foreignUuid('dropoff_stop_id')
                ->nullable()
                ->after('pickup_stop_id')
                ->constrained('route_stops')
                ->nullOnDelete();

            $table->timestamp('transport_assigned_at')->nullable()->after('dropoff_stop_id');

            $table->index('route_id');
            $table->index('pickup_stop_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['route_id']);
            $table->dropIndex(['pickup_stop_id']);
            $table->dropConstrainedForeignId('route_id');
            $table->dropConstrainedForeignId('pickup_stop_id');
            $table->dropConstrainedForeignId('dropoff_stop_id');
            $table->dropColumn('transport_assigned_at');
        });
    }
};
