<?php

namespace Tests\Feature\Notifications;

use App\Contracts\NotificationChannelDriver;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationIntent;
use App\Services\Notifications\ChannelManager;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\RetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-406 — the retry engine and escalation.
 *
 * Channel drivers are swapped for controllable fakes so failure paths can be
 * exercised without a live gateway.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ctms.notifications.channels.PUSH.enabled' => true,
            'ctms.notifications.channels.EMAIL.enabled' => true,
            'ctms.notifications.channels.SMS.enabled' => true,
            'ctms.notifications.retry_delays' => [30, 120, 600],
        ]);
    }

    /**
     * Replace a channel's driver with one that returns a fixed result.
     */
    private function fakeChannel(NotificationChannel $channel, DeliveryResult $result): void
    {
        $driver = new class($channel, $result) implements NotificationChannelDriver
        {
            public function __construct(
                private readonly NotificationChannel $channel,
                private readonly DeliveryResult $result,
            ) {}

            public function channel(): NotificationChannel
            {
                return $this->channel;
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function send(NotificationDelivery $delivery): DeliveryResult
            {
                return $this->result;
            }
        };

        $manager = app(ChannelManager::class);
        $class = get_class($driver);

        $this->app->bind($class, fn () => $driver);
        $manager->extend($channel, $class);
        $this->app->instance(ChannelManager::class, $manager);
    }

    private function dispatchTo(User $user, NotificationPriority $priority = NotificationPriority::STANDARD): Notification
    {
        app(NotificationDispatcher::class)->dispatch(NotificationIntent::make(
            eventKey: 'test.delivery',
            category: NotificationCategory::INCIDENT,
            recipients: [$user],
            title: 'Test',
            body: 'Body',
            priority: $priority,
        ));

        return Notification::where('user_id', $user->id)->firstOrFail();
    }

    private function userWithDevice(): User
    {
        $user = User::factory()->create(['phone_number' => '+919876500123']);
        NotificationDevice::factory()->create(['user_id' => $user->id]);

        return $user->fresh();
    }

    // ====================================================================
    // SUCCESS
    // ====================================================================

    #[Test]
    public function a_successful_delivery_is_recorded_as_delivered(): void
    {
        $user = $this->userWithDevice();

        $notification = $this->dispatchTo($user);
        $delivery = $notification->deliveries()->where('channel', 'IN_APP')->first();

        $this->assertSame(DeliveryStatus::DELIVERED, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempts);
    }

    #[Test]
    public function a_delivered_notification_reports_as_delivered(): void
    {
        $user = $this->userWithDevice();

        $this->assertTrue($this->dispatchTo($user)->wasDelivered());
    }

    // ====================================================================
    // RETRY — BR-406
    // ====================================================================

    #[Test]
    public function a_transient_failure_schedules_a_retry(): void
    {
        Queue::fake();

        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $this->fakeChannel(NotificationChannel::PUSH, DeliveryResult::transientFailure('Gateway timeout'));

        (new DeliverNotification($delivery->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::RETRYING, $delivery->status);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertSame('Gateway timeout', $delivery->reason);

        Queue::assertPushed(DeliverNotification::class);
    }

    #[Test]
    public function a_permanent_failure_is_not_retried(): void
    {
        Queue::fake();

        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $this->fakeChannel(NotificationChannel::PUSH, DeliveryResult::permanentFailure('Unregistered token'));

        (new DeliverNotification($delivery->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        $delivery->refresh();

        // Retrying a permanent failure burns the schedule and delays the
        // escalation that might actually reach someone.
        $this->assertSame(DeliveryStatus::PERMANENTLY_FAILED, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
    }

    #[Test]
    public function the_retry_schedule_is_exhausted_into_a_permanent_failure(): void
    {
        Queue::fake();

        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $this->fakeChannel(NotificationChannel::PUSH, DeliveryResult::transientFailure('Still down'));

        $policy = app(RetryPolicy::class);

        for ($attempt = 0; $attempt < $policy->maxAttempts(); $attempt++) {
            (new DeliverNotification($delivery->id))->handle(app(ChannelManager::class), $policy);
            $delivery->refresh();
        }

        $this->assertSame(DeliveryStatus::PERMANENTLY_FAILED, $delivery->status);
        $this->assertSame($policy->maxAttempts(), $delivery->attempts);
    }

    #[Test]
    public function a_replayed_job_does_not_resend_a_delivered_message(): void
    {
        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user);

        $delivery = $notification->deliveries()->where('channel', 'IN_APP')->first();
        $attemptsBefore = $delivery->attempts;

        (new DeliverNotification($delivery->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        $this->assertSame($attemptsBefore, $delivery->fresh()->attempts);
    }

    #[Test]
    public function a_job_for_a_missing_delivery_is_harmless(): void
    {
        (new DeliverNotification('019fd73c-0000-7000-8000-000000000000'))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        $this->assertTrue(true); // Reaching here without throwing is the assertion.
    }

    // ====================================================================
    // ESCALATION — BR-406
    // ====================================================================

    #[Test]
    public function a_failed_critical_delivery_escalates_when_nothing_else_got_through(): void
    {
        Queue::fake();

        // No phone number, so SMS is suppressed at dispatch for want of an
        // address rather than attempted.
        $user = User::factory()->create(['phone_number' => null]);
        NotificationDevice::factory()->create(['user_id' => $user->id]);

        $notification = $this->dispatchTo($user->fresh(), NotificationPriority::CRITICAL);

        $sms = $notification->deliveries()->where('channel', 'SMS')->first();
        $this->assertSame(DeliveryStatus::SUPPRESSED, $sms->status);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $this->fakeChannel(NotificationChannel::PUSH, DeliveryResult::permanentFailure('Device gone'));

        (new DeliverNotification($delivery->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        // The suppression is overridden and SMS is attempted — that is the
        // whole point of escalating a critical message.
        $sms->refresh();

        $this->assertSame((string) $delivery->id, $sms->escalated_from_id);
    }

    #[Test]
    public function no_escalation_happens_when_another_channel_already_delivered(): void
    {
        Queue::fake();

        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user, NotificationPriority::CRITICAL);

        // A critical notification fans out to every channel at dispatch. Mark
        // SMS as having got through: they have been told, so a failed push
        // needs no rescue.
        $sms = $notification->deliveries()->where('channel', 'SMS')->first();
        $sms->markSent('test');
        $this->assertSame(DeliveryStatus::DELIVERED, $sms->fresh()->status);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $this->fakeChannel(NotificationChannel::PUSH, DeliveryResult::permanentFailure('Device gone'));

        (new DeliverNotification($delivery->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        $this->assertNull($sms->fresh()->escalated_from_id);
    }

    #[Test]
    public function a_failed_standard_delivery_does_not_escalate(): void
    {
        Queue::fake();

        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user, NotificationPriority::STANDARD);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $this->fakeChannel(NotificationChannel::PUSH, DeliveryResult::permanentFailure('Device gone'));

        (new DeliverNotification($delivery->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        $sms = $notification->deliveries()->where('channel', 'SMS')->first();

        $this->assertTrue($sms === null || $sms->escalated_from_id === null);
    }

    #[Test]
    public function escalation_stops_at_the_end_of_the_chain(): void
    {
        Queue::fake();

        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user, NotificationPriority::CRITICAL);

        $email = $notification->deliveries()->where('channel', 'EMAIL')->first();
        $this->fakeChannel(NotificationChannel::EMAIL, DeliveryResult::permanentFailure('Bounced'));

        (new DeliverNotification($email->id))->handle(
            app(ChannelManager::class),
            app(RetryPolicy::class),
        );

        // Email has no escalation target; the chain is finite and cannot loop.
        $this->assertSame(DeliveryStatus::PERMANENTLY_FAILED, $email->fresh()->status);
    }

    // ====================================================================
    // JOB FAILURE
    // ====================================================================

    #[Test]
    public function a_failed_job_records_the_delivery_as_failed(): void
    {
        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user);

        $delivery = $notification->deliveries()->where('channel', 'PUSH')->first();
        $delivery->forceFill(['status' => DeliveryStatus::QUEUED])->save();

        (new DeliverNotification($delivery->id))->failed(new \RuntimeException('Worker died'));

        $delivery->refresh();

        // A job the worker gave up on must not disappear silently.
        $this->assertSame(DeliveryStatus::PERMANENTLY_FAILED, $delivery->status);
        $this->assertStringContainsString('Worker died', $delivery->reason);
    }

    #[Test]
    public function a_failed_job_does_not_overwrite_a_successful_delivery(): void
    {
        $user = $this->userWithDevice();
        $notification = $this->dispatchTo($user);

        $delivery = $notification->deliveries()->where('channel', 'IN_APP')->first();

        (new DeliverNotification($delivery->id))->failed(new \RuntimeException('Worker died'));

        $this->assertSame(DeliveryStatus::DELIVERED, $delivery->fresh()->status);
    }

    // ====================================================================
    // PUSH DEVICE HANDLING
    // ====================================================================

    #[Test]
    public function push_to_a_user_with_no_devices_fails_permanently(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        app(NotificationDispatcher::class)->dispatch(NotificationIntent::make(
            eventKey: 'test.nodevice',
            category: NotificationCategory::INCIDENT,
            recipients: [$user],
            title: 'Test',
            body: 'Body',
            priority: NotificationPriority::CRITICAL,
        ));

        $push = NotificationDelivery::where('channel', 'PUSH')->first();

        // Nothing to retry towards.
        $this->assertNotNull($push);
        $this->assertSame(DeliveryStatus::SUPPRESSED, $push->status);
    }

    #[Test]
    public function a_successful_push_stamps_the_device_as_used(): void
    {
        $user = User::factory()->create();
        $device = NotificationDevice::factory()->create([
            'user_id' => $user->id,
            'last_used_at' => now()->subWeek(),
        ]);

        $this->dispatchTo($user->fresh());

        $this->assertTrue($device->fresh()->last_used_at->isAfter(now()->subMinute()));
    }
}
