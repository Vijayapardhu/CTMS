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
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->string('registration_number')->unique();
            $table->string('department');
            $table->string('year_of_study');
            $table->string('hostel_name')->nullable();
            $table->string('hostel_room')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->boolean('has_valid_ticket')->default(false);
            $table->timestamp('ticket_expiry_date')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('registration_number');
            $table->index('department');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
