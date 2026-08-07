<?php

namespace App\Enums;

/**
 * The lifecycle of one attempt to reach one person on one channel (BR-407).
 *
 * `QUEUED → SENT → DELIVERED` on success; `FAILED → RETRYING` while attempts
 * remain; `PERMANENTLY_FAILED` when the retry schedule is exhausted.
 * `SUPPRESSED` records a notification deliberately not sent — muted, outside
 * a quiet-hours window, or the recipient lost entitlement between the event
 * and dispatch (BR-401). Suppression is recorded, never silent.
 */
enum DeliveryStatus: string
{
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case RETRYING = 'RETRYING';
    case PERMANENTLY_FAILED = 'PERMANENTLY_FAILED';
    case SUPPRESSED = 'SUPPRESSED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::DELIVERED, self::PERMANENTLY_FAILED, self::SUPPRESSED => true,
            default => false,
        };
    }
}
