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
        Schema::create('passenger_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')
                ->constrained('trips')
                ->onDelete('cascade');
            $table->foreignUuid('student_id')
                ->constrained('students')
                ->onDelete('restrict');
            $table->foreignUuid('route_stop_id')
                ->constrained('route_stops')
                ->onDelete('restrict');
            $table->enum('action', ['BOARDED', 'ALIGHTED'])->default('BOARDED');
            $table->timestamp('recorded_at');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();

            // Indexes
            $table->index('trip_id');
            $table->index('student_id');
            $table->index('recorded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passenger_logs');
    }
};
