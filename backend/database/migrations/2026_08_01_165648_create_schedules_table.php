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
        Schema::create('schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')
                ->constrained('routes')
                ->onDelete('cascade');
            $table->foreignUuid('bus_id')
                ->nullable()
                ->constrained('buses')
                ->nullOnDelete();
            $table->foreignUuid('driver_id')
                ->nullable()
                ->constrained('drivers')
                ->nullOnDelete();
            $table->time('departure_time');
            $table->time('arrival_time');
            $table->enum('day_of_week', ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY']);
            $table->enum('frequency', ['DAILY', 'WEEKDAYS', 'WEEKENDS', 'ONCE'])->default('DAILY');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('expected_passenger_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('route_id');
            $table->index('bus_id');
            $table->index('day_of_week');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
