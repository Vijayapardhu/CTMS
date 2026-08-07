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

            // Indexes for performance
            $table->index('original_bus_id');
            $table->index('replacement_bus_id');
            $table->index('trip_id');
            $table->index('start_time');
            $table->index('created_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('replacement_assignments');
    }
};
