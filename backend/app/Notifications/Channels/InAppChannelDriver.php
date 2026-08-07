<?php

namespace App\Notifications\Channels;

use App\Contracts\NotificationChannelDriver;
use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Notifications\DeliveryResult;

/**
 * In-app delivery.
 *
 * The notification row itself is the delivery: writing it made the message
 * visible in the notification centre. This driver exists so the channel is
 * recorded and auditable like any other rather than being a special case
 * everywhere else in the platform.
 */
class InAppChannelDriver implements NotificationChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::IN_APP;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        return $delivery->notification()->exists()
            ? DeliveryResult::success()
            : DeliveryResult::permanentFailure('The notification record no longer exists.');
    }
}
