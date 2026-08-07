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
        Schema::create('admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->string('designation');
            $table->string('department');
            $table->enum('access_level', ['SUPER_ADMIN', 'OPERATIONS', 'SUPPORT', 'VIEWER'])->default('VIEWER');
            $table->json('permissions')->nullable();
            $table->boolean('can_approve_incidents')->default(false);
            $table->boolean('can_manage_routes')->default(false);
            $table->boolean('can_manage_drivers')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('designation');
            $table->index('access_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
