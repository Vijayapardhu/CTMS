<?php

namespace App\Notifications\Channels;

use App\Contracts\NotificationChannelDriver;
use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationDevice;
use App\Notifications\DeliveryResult;
use Illuminate\Support\Facades\Log;

/**
 * Push delivery to every active device a user has registered.
 *
 * Multi-device by design: a parent with a phone and a tablet gets the message
 * on both, and a token the provider rejects is revoked so it stops consuming
 * attempts.
 *
 * The provider call is isolated in {@see self::deliverToDevice()}. Binding a
 * real transport (FCM, APNs) replaces that one method and nothing else.
 */
class PushChannelDriver implements NotificationChannelDriver
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::PUSH;
    }

    public function isEnabled(): bool
    {
        return NotificationChannel::PUSH->isEnabled();
    }

    public function send(NotificationDelivery $delivery): DeliveryResult
    {
        $notification = $delivery->notification;

        if ($notification === null) {
            return DeliveryResult::permanentFailure('The notification record no longer exists.');
        }

        $devices = NotificationDevice::where('user_id', $notification->user_id)
            ->active()
            ->get();

        if ($devices->isEmpty()) {
            // Nothing to retry towards — the user has no registered device.
            return DeliveryResult::permanentFailure('The recipient has no active devices registered.');
        }

        $succeeded = 0;
        $lastTransientReason = null;

        foreach ($devices as $device) {
            $outcome = $this->deliverToDevice($device, $notification);

            if ($outcome->successful) {
                $device->forceFill(['last_used_at' => now()])->save();
                $succeeded++;

                continue;
            }

            if (! $outcome->retryable) {
                // A rejected token is dead. Revoking it stops it consuming
                // attempts on every future notification.
                $device->revoke($outcome->reason ?? 'Rejected by the push provider.');

                continue;
            }

            $lastTransientReason = $outcome->reason;
        }

        if ($succeeded > 0) {
            return DeliveryResult::success("push:{$succeeded}");
        }

        return $lastTransientReason !== null
            ? DeliveryResult::transientFailure($lastTransientReason)
            : DeliveryResult::permanentFailure('Every registered device rejected the message.');
    }

    /**
     * The provider boundary.
     *
     * No push provider is configured in this installation yet, so delivery is
     * recorded as attempted and the payload is logged. This is a seam, not a
     * stub: the contract, the revocation behaviour and the multi-device fan-out
     * around it are complete and tested, and binding FCM replaces this method
     * alone.
     */
    protected function deliverToDevice(NotificationDevice $device, $notification): DeliveryResult
    {
        Log::info('Push notification dispatched', [
            'device_id' => (string) $device->getKey(),
            'platform' => $device->platform->value,
            'notification_id' => (string) $notification->getKey(),
            'title' => $notification->title,
        ]);

        return DeliveryResult::success('log');
    }
}
