<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification platform (FR-10, BR-400 to BR-408).
 *
 * Replaces the placeholder `notifications` table, which recorded a title and a
 * body and nothing about whether anyone actually received them. "Was the parent
 * told?" is a question this system must be able to answer months later, and it
 * needs delivery attempts, channels, failures and suppressions to do it.
 *
 * Four tables:
 *   notifications             what was said, to whom, about what
 *   notification_deliveries   every attempt to deliver it, per channel
 *   notification_devices      where push can be sent
 *   notification_preferences  what each person has chosen to receive
 */
return new class extends Migration
{
    public function up(): void
    {
        // The placeholder holds no production data and nothing writes to it.
        Schema::dropIfExists('notifications');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('event_key', 100);

            $table->enum('category', [
                'TRIP', 'ARRIVAL', 'ATTENDANCE', 'INCIDENT', 'TRANSPORT',
                'FLEET', 'ACCOUNT', 'FINANCE', 'ANNOUNCEMENT', 'SYSTEM',
            ]);

            $table->enum('priority', ['CRITICAL', 'STANDARD'])->default('STANDARD');

            $table->string('title');
            $table->text('body');

            /** Structured payload for deep-linking from the client. */
            $table->json('data')->nullable();

            /** The record this notification is about, for filtering and links. */
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            /**
             * BR-405 — one event never produces two notifications for one
             * recipient. The unique index makes that a guarantee rather than
             * an intention, and absorbs a replayed job without duplicating.
             */
            $table->string('dedup_key', 191);

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedup_key'], 'notifications_user_dedup_unique');
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'category']);
            $table->index('event_key');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();

            $table->enum('channel', ['PUSH', 'EMAIL', 'SMS', 'IN_APP']);

            $table->enum('status', [
                'QUEUED', 'SENT', 'DELIVERED', 'RETRYING',
                'PERMANENTLY_FAILED', 'SUPPRESSED',
            ])->default('QUEUED');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('first_attempted_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            /** Why it was suppressed, or why it failed. Never null on those. */
            $table->text('reason')->nullable();

            /** Provider reference, for reconciling against a gateway. */
            $table->string('provider_reference')->nullable();

            /** Set when this delivery was created by escalating another. */
            $table->foreignUuid('escalated_from_id')
                ->nullable()
                ->constrained('notification_deliveries')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['notification_id', 'channel'], 'delivery_notification_channel_unique');
            $table->index('status');
            $table->index('next_attempt_at');
        });

        Schema::create('notification_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('platform', ['IOS', 'ANDROID', 'WEB']);

            /**
             * Provider push token. Unique across the installation: a token
             * reassigned by the OS to a different user must move, not
             * duplicate, or the previous owner keeps receiving another
             * person's child's location.
             */
            $table->string('token', 512);
            $table->string('token_hash', 64)->unique();

            $table->string('device_name')->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('category', [
                'TRIP', 'ARRIVAL', 'ATTENDANCE', 'INCIDENT', 'TRANSPORT',
                'FLEET', 'ACCOUNT', 'FINANCE', 'ANNOUNCEMENT', 'SYSTEM',
            ]);

            /** Channels this user wants this category on. */
            $table->json('channels');

            $table->boolean('muted')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'category'], 'preference_user_category_unique');
        });

        // Quiet hours are per user, not per category.
        Schema::table('users', function (Blueprint $table) {
            $table->time('quiet_hours_start')->nullable()->after('last_login_at');
            $table->time('quiet_hours_end')->nullable()->after('quiet_hours_start');
            $table->string('locale', 10)->nullable()->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_start', 'quiet_hours_end', 'locale']);
        });

        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_devices');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');

        // Restore the placeholder shape so the migration is reversible.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->enum('type', ['TRIP_START', 'BUS_NEARING', 'DELAY', 'INCIDENT', 'MAINTENANCE', 'ANNOUNCEMENT']);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->index('type');
            $table->index('read_at');
            $table->index('created_at');
        });
    }
};
