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
        Schema::create('route_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')
                ->constrained('routes')
                ->onDelete('cascade');
            $table->string('stop_name');
            $table->integer('sequence_number');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('address');
            $table->string('landmark')->nullable();
            $table->integer('distance_from_start_km');
            $table->integer('estimated_arrival_minutes');
            $table->integer('waiting_time_minutes')->default(5);
            $table->enum('stop_type', ['PICKUP', 'DROPOFF', 'BOTH'])->default('BOTH');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('route_id');
            $table->index('sequence_number');
            $table->index(['latitude', 'longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
