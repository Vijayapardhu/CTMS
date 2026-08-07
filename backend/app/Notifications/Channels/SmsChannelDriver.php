<?php

namespace App\Notifications\Channels;

use App\Contracts\NotificationChannelDriver;
use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Notifications\DeliveryResult;
use Illuminate\Support\Facades\Log;

/**
 * SMS delivery.
 *
 * Disabled by default: an institution without an SMS contract switches it off
 * and the platform routes around it rather than accumulating failures against
 * a gateway that is not there.
 *
 * The gateway boundary is {@see self::deliverToNumber()}; binding Twilio or a
 * regional aggregator replaces that method alone.
 */
class SmsChannelDriver implements NotificationChannelDriver
{
    /** Single-segment GSM-7 length. Longer messages bill as multiple parts. */
    private const SEGMENT_LENGTH = 160;

    public function channel(): NotificationChannel
    {
        return NotificationChannel::SMS;
    }

    public function isEnabled(): bool
    {
        return NotificationChannel::SMS->isEnabled();
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        $notification = $delivery->notification;
        $recipient = $notification?->user;

        if ($notification === null || $recipient === null) {
            return DeliveryResult::permanentFailure('The notification record no longer exists.');
        }

        if (blank($recipient->phone_number)) {
            return DeliveryResult::permanentFailure('The recipient has no phone number.');
        }

        return $this->deliverToNumber(
            $recipient->phone_number,
            $this->composeMessage($notification->title, $notification->body),
        );
    }

    /**
     * SMS is charged per segment, so the message is trimmed to one rather than
     * silently costing three times as much per notification.
     */
    private function composeMessage(string $title, string $body): string
    {
        $message = trim($title.': '.$body);

        return mb_strlen($message) <= self::SEGMENT_LENGTH
            ? $message
            : mb_substr($message, 0, self::SEGMENT_LENGTH - 1).'…';
    }

    /**
     * The gateway boundary. No provider is bound in this installation.
     */
    protected function deliverToNumber(string $number, string $message): DeliveryResult
    {
        Log::info('SMS notification dispatched', [
            'to' => substr($number, 0, 4).'…',
            'length' => mb_strlen($message),
        ]);

        return DeliveryResult::success('log');
    }
}
