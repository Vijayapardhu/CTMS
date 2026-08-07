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
        Schema::create('buses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('registration_number')->unique();
            $table->string('vehicle_name');
            $table->string('model');
            $table->year('year_of_manufacture');
            $table->integer('seating_capacity');
            $table->enum('status', ['AVAILABLE', 'RUNNING', 'MAINTENANCE', 'BREAKDOWN', 'OFFLINE'])->default('AVAILABLE');
            $table->string('fuel_type');
            $table->decimal('mileage', 8, 2)->default(0);
            $table->timestamp('last_maintenance_date')->nullable();
            $table->timestamp('next_maintenance_due')->nullable();
            $table->string('color')->nullable();
            $table->string('gps_device_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('registration_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buses');
    }
};
