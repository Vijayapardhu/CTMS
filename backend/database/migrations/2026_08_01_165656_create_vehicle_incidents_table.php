<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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

            // Indexes for performance
            $table->index('trip_id');
            $table->index('driver_id');
            $table->index('bus_id');
            $table->index('incident_type');
            $table->index('severity');
            $table->index('status');
            $table->index('reported_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_incidents');
    }
};
