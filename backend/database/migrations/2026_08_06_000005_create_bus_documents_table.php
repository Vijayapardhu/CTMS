<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory vehicle documents (BR-055).
 *
 * A bus whose fitness certificate, insurance or permit has lapsed may not be
 * assigned to a driver or scheduled. Without this register the rule cannot be
 * enforced at all, which is why it existed in the blueprint and nowhere in the
 * code.
 *
 * Documents are versioned by renewal rather than overwritten: an incident
 * investigation months later must be able to establish what cover was in force
 * on the day, so a superseded certificate is retained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bus_id')->constrained('buses')->cascadeOnDelete();

            $table->enum('document_type', [
                'FITNESS', 'INSURANCE', 'POLLUTION', 'PERMIT', 'ROAD_TAX',
            ]);

            $table->string('document_number')->nullable();
            $table->string('issuing_authority')->nullable();
            $table->date('issued_on');
            $table->date('expires_on');

            // Stored outside the web root; served only through an authorising
            // check (BR-367).
            $table->string('file_path')->nullable();

            $table->text('notes')->nullable();

            // A renewal supersedes the previous certificate rather than
            // replacing the row, so history survives.
            $table->foreignUuid('superseded_by_id')
                ->nullable()
                ->constrained('bus_documents')
                ->nullOnDelete();

            $table->foreignUuid('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bus_id', 'document_type']);
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_documents');
    }
};
