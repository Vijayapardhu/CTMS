<?php

namespace App\Notifications\Channels;

use App\Contracts\NotificationChannelDriver;
use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Notifications\DeliveryResult;
use Illuminate\Support\Facades\Mail;

/**
 * Email delivery through Laravel's configured mailer.
 */
class EmailChannelDriver implements NotificationChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    public function isEnabled(): bool
    {
        return NotificationChannel::EMAIL->isEnabled();
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        $notification = $delivery->notification;
        $recipient = $notification?->user;

        if ($notification === null || $recipient === null) {
            return DeliveryResult::permanentFailure('The notification record no longer exists.');
        }

        if (blank($recipient->email)) {
            return DeliveryResult::permanentFailure('The recipient has no email address.');
        }

        try {
            Mail::raw($notification->body, function ($message) use ($recipient, $notification) {
                $message->to($recipient->email)->subject($notification->title);
            });

            return DeliveryResult::success();
        } catch (\Throwable $e) {
            // A mail transport failure is worth another attempt; an invalid
            // address is not, and the transport reports that differently.
            return DeliveryResult::transientFailure($e->getMessage());
        }
    }
}
