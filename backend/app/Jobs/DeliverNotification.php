<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Services\Notifications\ChannelManager;
use App\Services\Notifications\RetryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Attempts one delivery, and schedules the next attempt or the escalation.
 *
 * The retry engine (BR-406). Idempotent by design: a delivery already in a
 * terminal state is left alone, so a replayed job cannot re-send a message or
 * reset a failure.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(ChannelManager $channels, RetryPolicy $retries): void
    {
        $delivery = NotificationDelivery::with('notification.user')->find($this->deliveryId);

        if ($delivery === null) {
            return; // Purged, or its notification was removed.
        }

        // A replayed job must not re-send an already-delivered message.
        if ($delivery->isTerminal()) {
            return;
        }

        $driver = $channels->driver($delivery->channel);

        if (! $driver->isEnabled()) {
            $delivery->markSuppressed('Channel is disabled for this installation.');

            return;
        }

        $attempts = $delivery->attempts + 1;

        $delivery->forceFill([
            'attempts' => $attempts,
            'first_attempted_at' => $delivery->first_attempted_at ?? now(),
            'last_attempted_at' => now(),
            'status' => DeliveryStatus::SENT,
        ])->save();

        $result = $driver->send($delivery);

        if ($result->successful) {
            $delivery->markSent($result->providerReference);

            return;
        }

        $result->retryable && $retries->hasAttemptsRemaining($attempts)
            ? $this->scheduleRetry($delivery, $attempts, $result->reason, $retries)
            : $this->fail($delivery, $result->reason ?? 'Delivery failed.', $channels);
    }

    private function scheduleRetry(
        NotificationDelivery $delivery,
        int $attempts,
        ?string $reason,
        RetryPolicy $retries,
    ): void {
        $nextAttempt = $retries->nextAttemptAfter($attempts);

        if ($nextAttempt === null) {
            $this->fail($delivery, $reason ?? 'Retry schedule exhausted.', app(ChannelManager::class));

            return;
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::RETRYING,
            'next_attempt_at' => $nextAttempt,
            'reason' => $reason,
        ])->save();

        self::dispatch($delivery->getKey())
            ->delay($nextAttempt)
            ->onQueue($delivery->notification->priority->queue());
    }

    /**
     * Record the permanent failure, and escalate if the message was critical.
     */
    private function fail(NotificationDelivery $delivery, string $reason, ChannelManager $channels): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::PERMANENTLY_FAILED,
            'next_attempt_at' => null,
            'reason' => $reason,
        ])->save();

        $notification = $delivery->notification;

        if ($notification === null || ! $notification->priority->escalatesOnFailure()) {
            return;
        }

        $this->escalate($delivery, $channels);
    }

    /**
     * BR-406 — a failed critical delivery escalates to an alternate channel.
     *
     * The escalation chain is finite (push → SMS → email → nothing) and each
     * link is created once, so a cascade cannot loop.
     */
    private function escalate(NotificationDelivery $delivery, ChannelManager $channels): void
    {
        // A critical notification fans out to every addressable channel at
        // dispatch (BR-402), so escalation is only meaningful when none of
        // them actually reached the person. If SMS already delivered, a failed
        // push needs no rescue — they have been told.
        if ($this->reachedByAnotherChannel($delivery)) {
            return;
        }

        $target = $delivery->channel->escalationTarget();

        if ($target === null || ! $target->isEnabled()) {
            Log::warning('Critical notification could not be escalated', [
                'delivery_id' => (string) $delivery->getKey(),
                'channel' => $delivery->channel->value,
            ]);

            return;
        }

        $existing = NotificationDelivery::where('notification_id', $delivery->notification_id)
            ->where('channel', $target->value)
            ->first();

        // A critical escalation overrides a suppression — that is the whole
        // point of escalating. An already-attempted channel is left alone.
        if ($existing !== null && $existing->status !== DeliveryStatus::SUPPRESSED) {
            return;
        }

        $escalated = $existing ?? new NotificationDelivery;

        $escalated->forceFill([
            'notification_id' => $delivery->notification_id,
            'channel' => $target->value,
            'status' => DeliveryStatus::QUEUED,
            'attempts' => 0,
            'reason' => null,
            'next_attempt_at' => null,
            'escalated_from_id' => $delivery->getKey(),
        ])->save();

        self::dispatch($escalated->getKey())->onQueue('critical');
    }

    /**
     * Whether any channel that actually reaches out to the person has already
     * delivered this notification.
     *
     * IN_APP does not count: writing the notification centre record is not
     * the same as reaching somebody who is not looking at the app.
     */
    private function reachedByAnotherChannel(NotificationDelivery $delivery): bool
    {
        return NotificationDelivery::where('notification_id', $delivery->notification_id)
            ->whereKeyNot($delivery->getKey())
            ->where('channel', '!=', NotificationChannel::IN_APP->value)
            ->where('status', DeliveryStatus::DELIVERED->value)
            ->exists();
    }

    /**
     * The queue worker gave up on the job itself — distinct from the delivery
     * failing. Recorded so it cannot disappear silently.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Notification delivery job failed', [
            'delivery_id' => $this->deliveryId,
            'error' => $exception->getMessage(),
        ]);

        NotificationDelivery::whereKey($this->deliveryId)
            ->whereNotIn('status', [
                DeliveryStatus::DELIVERED->value,
                DeliveryStatus::SUPPRESSED->value,
            ])
            ->update([
                'status' => DeliveryStatus::PERMANENTLY_FAILED->value,
                'reason' => 'Delivery job failed: '.$exception->getMessage(),
                'next_attempt_at' => null,
            ]);
    }
}
