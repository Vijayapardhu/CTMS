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
        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->string('license_number')->unique();
            $table->string('license_class');
            $table->date('license_expiry_date');
            $table->string('vehicle_registration')->nullable();
            $table->enum('status', ['AVAILABLE', 'ON_TRIP', 'LEAVE', 'OFF_DUTY'])->default('AVAILABLE');
            $table->decimal('current_latitude', 10, 8)->nullable();
            $table->decimal('current_longitude', 11, 8)->nullable();
            $table->timestamp('last_gps_update')->nullable();
            $table->integer('total_trips')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->text('violations_history')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('license_number');
            $table->index('status');
            $table->index(['current_latitude', 'current_longitude']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
