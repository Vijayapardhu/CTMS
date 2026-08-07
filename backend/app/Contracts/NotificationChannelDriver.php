<?php

namespace App\Contracts;

use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Notifications\DeliveryResult;

/**
 * A way of getting a message to a person.
 *
 * Drivers are deliberately thin: they attempt one delivery and report what
 * happened. Retry scheduling, escalation and recording are the platform's job,
 * so a new channel is a small, self-contained addition.
 */
interface NotificationChannelDriver
{
    public function channel(): NotificationChannel;

    /**
     * Whether this driver can be used at all — configured, credentialed and
     * switched on for the installation.
     */
    public function isEnabled(): bool;

    /**
     * Attempt one delivery.
     *
     * A driver must not throw for an expected failure; it reports it, so the
     * platform can decide whether to retry. Throwing is reserved for a bug.
     */
    public function send(NotificationDelivery $delivery): DeliveryResult;
}
