<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationCategory;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\NotificationIntent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns an intent into notification records and queued deliveries.
 *
 * The entry point every module reaches, directly or through an event.
 * Everything downstream of here — channels, retries, escalation — is the
 * platform's business.
 */
class NotificationDispatcher
{
    public function __construct(private readonly PreferenceResolver $preferences) {}

    /**
     * Dispatch one intent to all its recipients.
     *
     * BR-408 — this must never break the operation that published the event.
     * A notification failure degrades; it does not roll back a completed trip.
     *
     * @return array<int, Notification>
     */
    public function dispatch(NotificationIntent $intent): array
    {
        $created = [];

        foreach ($intent->recipients() as $recipient) {
            try {
                $notification = $this->dispatchToRecipient($intent, $recipient);

                if ($notification !== null) {
                    $created[] = $notification;
                }
            } catch (\Throwable $e) {
                // One bad recipient must not cost the others their message.
                Log::error('Notification dispatch failed for recipient', [
                    'event_key' => $intent->eventKey,
                    'user_id' => (string) $recipient->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    /**
     * @return Notification|null Null when deduplicated away (BR-405).
     */
    private function dispatchToRecipient(NotificationIntent $intent, User $recipient): ?Notification
    {
        // BR-401 — entitlement is evaluated at dispatch, not at event time,
        // so an account deactivated in between is not told about operational
        // matters it is no longer entitled to.
        //
        // ACCOUNT is the deliberate exception: those messages are *about* the
        // account's own state, and "your account has been deactivated" is
        // exactly the notification a deactivated user must still receive.
        if (! $recipient->is_active && $intent->category !== NotificationCategory::ACCOUNT) {
            return null;
        }

        $notification = $this->createNotification($intent, $recipient);

        if ($notification === null) {
            return null; // Already sent; the unique index absorbed a replay.
        }

        $resolution = $this->preferences->resolve(
            $recipient,
            $intent->category,
            $intent->priority,
        );

        // Record the deliberate non-deliveries as well as the attempts.
        foreach ($resolution['suppressed'] as $channelValue => $reason) {
            $this->createDelivery($notification, $channelValue, DeliveryStatus::SUPPRESSED, $reason);
        }

        foreach ($resolution['channels'] as $channel) {
            $delivery = $this->createDelivery($notification, $channel->value, DeliveryStatus::QUEUED);

            if ($delivery !== null) {
                DeliverNotification::dispatch($delivery->getKey())
                    ->onQueue($intent->priority->queue());
            }
        }

        return $notification;
    }

    /**
     * BR-405 — one event, one notification per recipient.
     *
     * The unique index on (user_id, dedup_key) is what makes this a guarantee.
     * A replayed job or a re-published event collides and is absorbed rather
     * than telling somebody the same thing twice.
     */
    private function createNotification(NotificationIntent $intent, User $recipient): ?Notification
    {
        try {
            return DB::transaction(function () use ($intent, $recipient) {
                $notification = new Notification;

                $notification->forceFill([
                    'user_id' => $recipient->getKey(),
                    'event_key' => $intent->eventKey,
                    'category' => $intent->category,
                    'priority' => $intent->priority,
                    'title' => $intent->title,
                    'body' => $intent->body,
                    'data' => $intent->data,
                    'subject_type' => $intent->subject?->getMorphClass(),
                    'subject_id' => $intent->subject?->getKey(),
                    'dedup_key' => $intent->dedupKeyFor($recipient),
                ])->save();

                return $notification;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function createDelivery(
        Notification $notification,
        string $channel,
        DeliveryStatus $status,
        ?string $reason = null,
    ): ?NotificationDelivery {
        try {
            $delivery = new NotificationDelivery;

            $delivery->forceFill([
                'notification_id' => $notification->getKey(),
                'channel' => $channel,
                'status' => $status,
                'reason' => $reason,
            ])->save();

            return $delivery;
        } catch (UniqueConstraintViolationException) {
            return null; // This channel already has a delivery record.
        }
    }
}
