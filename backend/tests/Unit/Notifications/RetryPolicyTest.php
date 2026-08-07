<?php

namespace Tests\Unit\Notifications;

use App\Services\Notifications\RetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-406 — the retry schedule.
 *
 * Defined once for the whole platform so no module invents its own backoff.
 */
class RetryPolicyTest extends TestCase
{
    private RetryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ctms.notifications.retry_delays' => [30, 120, 600]]);

        $this->policy = new RetryPolicy;
    }

    #[Test]
    public function the_default_schedule_allows_four_attempts(): void
    {
        // Immediate, then +30s, +2m, +10m.
        $this->assertSame(4, $this->policy->maxAttempts());
    }

    #[Test]
    public function it_schedules_each_configured_delay_in_order(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->assertSame(30, (int) now()->diffInSeconds($this->policy->nextAttemptAfter(1)));
        $this->assertSame(120, (int) now()->diffInSeconds($this->policy->nextAttemptAfter(2)));
        $this->assertSame(600, (int) now()->diffInSeconds($this->policy->nextAttemptAfter(3)));

        $this->travelBack();
    }

    #[Test]
    public function the_schedule_is_exhausted_after_the_last_delay(): void
    {
        $this->assertNull($this->policy->nextAttemptAfter(4));
        $this->assertNull($this->policy->nextAttemptAfter(99));
    }

    #[Test]
    public function attempts_remaining_tracks_the_ceiling(): void
    {
        $this->assertTrue($this->policy->hasAttemptsRemaining(1));
        $this->assertTrue($this->policy->hasAttemptsRemaining(3));
        $this->assertFalse($this->policy->hasAttemptsRemaining(4));
        $this->assertFalse($this->policy->hasAttemptsRemaining(5));
    }

    #[Test]
    public function the_schedule_is_configurable(): void
    {
        config(['ctms.notifications.retry_delays' => [10]]);

        $policy = new RetryPolicy;

        $this->assertSame(2, $policy->maxAttempts());
        $this->assertNull($policy->nextAttemptAfter(2));
    }

    #[Test]
    public function an_empty_schedule_means_one_attempt_only(): void
    {
        config(['ctms.notifications.retry_delays' => []]);

        $policy = new RetryPolicy;

        $this->assertSame(1, $policy->maxAttempts());
        $this->assertNull($policy->nextAttemptAfter(1));
    }
}
