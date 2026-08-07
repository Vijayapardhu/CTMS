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
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')
                ->constrained('schedules')
                ->onDelete('cascade');
            $table->foreignUuid('bus_id')
                ->constrained('buses')
                ->onDelete('restrict');
            $table->foreignUuid('driver_id')
                ->constrained('drivers')
                ->onDelete('restrict');
            $table->foreignUuid('route_id')
                ->constrained('routes')
                ->onDelete('restrict');
            $table->date('trip_date');
            $table->time('scheduled_departure_time');
            $table->time('actual_departure_time')->nullable();
            $table->time('scheduled_arrival_time');
            $table->time('actual_arrival_time')->nullable();
            $table->enum('status', ['SCHEDULED', 'RUNNING', 'COMPLETED', 'CANCELLED'])->default('SCHEDULED');
            $table->integer('booked_seat_count')->default(0);
            $table->integer('occupied_seat_count')->default(0);
            $table->decimal('current_latitude', 10, 8)->nullable();
            $table->decimal('current_longitude', 11, 8)->nullable();
            $table->timestamp('last_gps_update')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->decimal('average_speed_kmh', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('trip_date');
            $table->index('status');
            $table->index(['bus_id', 'trip_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
