<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 7 — reports, audit and data protection (FR-15, BR-500..BR-512).
 *
 * `audit_logs` already exists and is deliberately left alone: it has no
 * `updated_at`, which is the shape an append-only table wants.
 *
 * What is missing is the record of *reads*. BR-501 requires every staff access
 * to a student's personal data to be logged with the accessing identity, and
 * an audit trail that only records writes cannot answer "who looked at this
 * child's location history" — which is the question that actually matters when
 * something goes wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Who looked.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Whose data. Kept as a plain uuid rather than a constrained key so
            // the access record outlives the subject's deletion — the whole
            // point is that it cannot be made to disappear.
            $table->uuid('subject_id')->nullable();
            $table->string('subject_type');

            $table->string('purpose');
            $table->string('data_class');

            // BR-502 — a bulk export is a different act from opening one
            // record, and is flagged so it can be reviewed on its own.
            $table->boolean('is_bulk')->default(false);
            $table->unsignedInteger('record_count')->default(1);
            $table->text('reason')->nullable();

            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
            $table->index('created_at');
            $table->index('is_bulk');
        });

        Schema::create('retention_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('data_class');
            $table->unsignedInteger('retention_days');

            // BR-505 — a purge that would break referential history refuses to
            // run, and the refusal is recorded rather than swallowed.
            $table->enum('outcome', ['DRY_RUN', 'PURGED', 'REFUSED'])->default('DRY_RUN');
            $table->unsignedInteger('records_matched')->default(0);
            $table->unsignedInteger('records_purged')->default(0);
            $table->text('refusal_reason')->nullable();

            $table->timestamp('cutoff_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['data_class', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_runs');
        Schema::dropIfExists('data_access_logs');
    }
};
