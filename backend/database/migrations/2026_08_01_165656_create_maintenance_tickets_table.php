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
        Schema::create('maintenance_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bus_id')->constrained('buses')->onDelete('cascade');
            $table->text('issue_description');
            $table->enum('status', ['OPEN', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('OPEN');
            $table->enum('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT'])->default('MEDIUM');
            $table->foreignUuid('assigned_to_id')->nullable()->constrained('users')->onDelete('set null');
            $table->dateTime('scheduled_date')->nullable();
            $table->dateTime('completion_date')->nullable();
            $table->decimal('estimated_cost', 10, 2)->nullable();
            $table->decimal('actual_cost', 10, 2)->nullable();
            $table->text('parts_used')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('bus_id');
            $table->index('status');
            $table->index('priority');
            $table->index('assigned_to_id');
            $table->index('scheduled_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_tickets');
    }
};
