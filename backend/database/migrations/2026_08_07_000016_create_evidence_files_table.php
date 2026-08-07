<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-367 — evidence files.
 *
 * The `photo_path` columns this replaces held whatever string a client sent.
 * No upload ever happened, nothing checked a MIME type, and the safety rules
 * that require a photograph — for a failed brake check, for every operational
 * incident — were satisfied by typing a filename. The column is dropped rather
 * than kept alongside, so there is one way to attach evidence instead of two.
 *
 * The stored path is never exposed. Clients hold an id and fetch through an
 * endpoint that checks who they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_files', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->enum('category', [
                'INCIDENT_PHOTO', 'INSPECTION_PHOTO', 'MAINTENANCE_ATTACHMENT',
                'DRIVER_DOCUMENT', 'VEHICLE_CERTIFICATE',
            ]);

            // Where it actually lives. `path` is generated, never client-supplied.
            $table->string('disk', 40);
            $table->string('path');

            // Kept for display and for the download filename. Never used to
            // build a path — that is how a traversal gets in.
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');

            // Lets a duplicate upload be recognised, and proves the bytes have
            // not changed since they were taken as evidence.
            $table->string('checksum', 64)->index();

            $table->foreignUuid('uploaded_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // What it ends up attached to. Nullable because the file is
            // uploaded first and attached when the report it belongs to is
            // submitted — the driver photographs the damage before they have
            // finished writing the report, and often before they have signal.
            $table->nullableUuidMorphs('attachable');

            $table->timestamp('attached_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'created_at']);
            $table->index('uploaded_by_id');
        });

        Schema::table('vehicle_incidents', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        Schema::table('vehicle_inspection_items', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_inspection_items', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });

        Schema::table('vehicle_incidents', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });

        Schema::dropIfExists('evidence_files');
    }
};
