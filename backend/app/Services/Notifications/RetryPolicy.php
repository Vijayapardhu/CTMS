<?php

namespace App\Services\Notifications;

use Carbon\CarbonInterface;

/**
 * The delivery retry schedule (BR-406).
 *
 * Defined once, for the whole platform, so no module invents its own backoff.
 * Attempt 1 is immediate; the configured delays apply before attempts 2..N.
 * With the default schedule that is: now, +30s, +2m, +10m, then give up.
 */
class RetryPolicy
{
    /**
     * @return array<int, int> Delays in seconds, in order.
     */
    public function delays(): array
    {
        $configured = config('ctms.notifications.retry_delays', [30, 120, 600]);

        return array_values(array_map('intval', (array) $configured));
    }

    /**
     * Total attempts a delivery gets: the first, plus one per configured delay.
     */
    public function maxAttempts(): int
    {
        return count($this->delays()) + 1;
    }

    /**
     * When to try again after `$attemptsMade` attempts, or null when the
     * schedule is exhausted and the delivery is permanently failed.
     */
    public function nextAttemptAfter(int $attemptsMade): ?CarbonInterface
    {
        $delays = $this->delays();

        // attemptsMade of 1 means the first attempt is done; the next delay is
        // the first in the list.
        $index = $attemptsMade - 1;

        if ($index < 0 || $index >= count($delays)) {
            return null;
        }

        return now()->addSeconds($delays[$index]);
    }

    public function hasAttemptsRemaining(int $attemptsMade): bool
    {
        return $attemptsMade < $this->maxAttempts();
    }
}
