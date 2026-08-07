<?php

namespace App\Listeners;

use App\Contracts\NotifiesUsers;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * The single bridge from domain events to the notification platform.
 *
 * Every event implementing {@see NotifiesUsers} passes through here. There is
 * one listener rather than one per event because the platform's job is
 * identical in every case: take the intents, dispatch them, and never let a
 * failure reach the publisher (BR-408).
 */
class DispatchEventNotifications
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(object $event): void
    {
        if (! $event instanceof NotifiesUsers) {
            return;
        }

        foreach ($event->notificationIntents() as $intent) {
            if (! $intent->hasRecipients()) {
                continue; // Nothing to say to nobody.
            }

            try {
                $this->dispatcher->dispatch($intent);
            } catch (\Throwable $e) {
                // BR-408 — notification failure degrades; it never blocks or
                // rolls back the operation that published the event.
                Log::error('Failed to dispatch notifications for event', [
                    'event' => $event::class,
                    'event_key' => $intent->eventKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
