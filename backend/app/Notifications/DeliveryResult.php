<?php

namespace App\Notifications;

/**
 * What happened on one delivery attempt.
 *
 * The distinction that matters is `retryable`: a gateway timeout is worth
 * another go, an unregistered device token never will be. Retrying a permanent
 * failure burns the schedule and delays the escalation that might actually
 * reach someone.
 */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly bool $retryable,
        public readonly ?string $reason = null,
        public readonly ?string $providerReference = null,
    ) {}

    public static function success(?string $providerReference = null): self
    {
        return new self(successful: true, retryable: false, providerReference: $providerReference);
    }

    /**
     * A transient failure: the transport was unavailable, but the address is
     * still good.
     */
    public static function transientFailure(string $reason): self
    {
        return new self(successful: false, retryable: true, reason: $reason);
    }

    /**
     * A permanent failure: retrying cannot help. An invalid token, a missing
     * phone number, a rejected address.
     */
    public static function permanentFailure(string $reason): self
    {
        return new self(successful: false, retryable: false, reason: $reason);
    }
}
