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
        Schema::create('bus_merge_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bus1_id')->constrained('buses')->onDelete('cascade');
            $table->foreignUuid('bus2_id')->constrained('buses')->onDelete('cascade');
            $table->text('reason');
            $table->decimal('estimated_savings', 10, 2)->nullable();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'IMPLEMENTED'])->default('PENDING');
            $table->foreignUuid('recommended_by_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('decision_by_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('decision_date')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('bus1_id');
            $table->index('bus2_id');
            $table->index('status');
            $table->index('recommended_by_id');
            $table->index('decision_by_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bus_merge_recommendations');
    }
};
