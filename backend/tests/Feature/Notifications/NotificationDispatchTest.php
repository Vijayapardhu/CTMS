<?php

namespace Tests\Feature\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDevice;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationIntent;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-400, BR-401, BR-405, BR-407, BR-408 — turning an intent into records
 * and queued deliveries.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = app(NotificationDispatcher::class);

        config([
            'ctms.notifications.channels.PUSH.enabled' => true,
            'ctms.notifications.channels.EMAIL.enabled' => true,
            'ctms.notifications.channels.SMS.enabled' => false,
        ]);
    }

    private function intent(array $recipients, array $overrides = []): NotificationIntent
    {
        return NotificationIntent::make(
            eventKey: $overrides['eventKey'] ?? 'test.event',
            category: $overrides['category'] ?? NotificationCategory::TRIP,
            recipients: $recipients,
            title: $overrides['title'] ?? 'Your bus has departed',
            body: $overrides['body'] ?? 'Route 7 left the depot at 07:15.',
            priority: $overrides['priority'] ?? NotificationPriority::STANDARD,
            data: $overrides['data'] ?? ['route' => '7'],
            subject: $overrides['subject'] ?? null,
            dedupKey: $overrides['dedupKey'] ?? null,
        );
    }

    // ====================================================================
    // CREATING NOTIFICATIONS
    // ====================================================================

    #[Test]
    public function it_creates_a_notification_per_recipient(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$alice, $bob]));

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['user_id' => $alice->id, 'event_key' => 'test.event']);
        $this->assertDatabaseHas('notifications', ['user_id' => $bob->id]);
    }

    #[Test]
    public function it_records_the_payload_and_category(): void
    {
        $user = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$user]));

        $notification = Notification::first();

        $this->assertSame(NotificationCategory::TRIP, $notification->category);
        $this->assertSame(NotificationPriority::STANDARD, $notification->priority);
        $this->assertSame(['route' => '7'], $notification->data);
        $this->assertNull($notification->read_at);
    }

    #[Test]
    public function it_links_the_notification_to_its_subject(): void
    {
        $user = User::factory()->create();
        $subject = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$user], ['subject' => $subject]));

        $notification = Notification::first();

        $this->assertSame((string) $subject->getKey(), $notification->subject_id);
        $this->assertNotNull($notification->subject_type);
    }

    #[Test]
    public function a_recipient_listed_twice_is_told_once(): void
    {
        $user = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$user, $user]));

        $this->assertDatabaseCount('notifications', 1);
    }

    // ====================================================================
    // DEDUPLICATION — BR-405
    // ====================================================================

    #[Test]
    public function the_same_event_does_not_notify_twice(): void
    {
        $user = User::factory()->create();
        $subject = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$user], ['subject' => $subject]));
        $this->dispatcher->dispatch($this->intent([$user], ['subject' => $subject]));

        // A replayed job or a re-published event must be absorbed.
        $this->assertDatabaseCount('notifications', 1);
    }

    #[Test]
    public function a_distinct_dedup_key_produces_a_distinct_notification(): void
    {
        $user = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$user], ['dedupKey' => 'stop:1']));
        $this->dispatcher->dispatch($this->intent([$user], ['dedupKey' => 'stop:2']));

        // "Bus approaching" fires once per stop, not once per trip.
        $this->assertDatabaseCount('notifications', 2);
    }

    #[Test]
    public function deduplication_is_per_recipient(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$alice], ['dedupKey' => 'shared']));
        $this->dispatcher->dispatch($this->intent([$bob], ['dedupKey' => 'shared']));

        $this->assertDatabaseCount('notifications', 2);
    }

    // ====================================================================
    // ENTITLEMENT AT DISPATCH — BR-401
    // ====================================================================

    #[Test]
    public function a_deactivated_recipient_is_not_notified(): void
    {
        $active = User::factory()->create();
        $deactivated = User::factory()->inactive()->create();

        // Entitlement is evaluated at dispatch, not when the event fired.
        $this->dispatcher->dispatch($this->intent([$active, $deactivated]));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $active->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $deactivated->id]);
    }

    // ====================================================================
    // DELIVERY RECORDS — BR-407
    // ====================================================================

    #[Test]
    public function it_creates_a_delivery_for_every_resolved_channel(): void
    {
        $user = User::factory()->create();
        NotificationDevice::factory()->create(['user_id' => $user->id]);

        $this->dispatcher->dispatch($this->intent([$user->fresh()]));

        $notification = Notification::first();

        $this->assertTrue($notification->deliveries()->where('channel', 'PUSH')->exists());
        $this->assertTrue($notification->deliveries()->where('channel', 'IN_APP')->exists());
    }

    #[Test]
    public function suppressed_channels_are_recorded_with_a_reason(): void
    {
        $user = User::factory()->create();

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::TRIP->value,
            'channels' => ['IN_APP'],
            'muted' => false,
        ]);

        $this->dispatcher->dispatch($this->intent([$user->fresh()]));

        $suppressed = NotificationDelivery::where('status', DeliveryStatus::SUPPRESSED->value)->get();

        // "Why didn't I get told?" must be answerable — suppression is
        // recorded, never silent.
        $this->assertTrue($suppressed->isNotEmpty());
        $this->assertNotNull($suppressed->first()->reason);
    }

    #[Test]
    public function every_channel_gets_exactly_one_delivery_record(): void
    {
        $user = User::factory()->create();
        NotificationDevice::factory()->create(['user_id' => $user->id]);

        $this->dispatcher->dispatch($this->intent([$user->fresh()]));

        $notification = Notification::first();

        // pluck() applies the model's casts, so these come back as enums.
        $channels = $notification->deliveries()
            ->pluck('channel')
            ->map(fn ($channel) => $channel->value)
            ->all();

        $this->assertSame(count($channels), count(array_unique($channels)));
    }

    #[Test]
    public function a_disabled_channel_is_recorded_as_suppressed(): void
    {
        config(['ctms.notifications.channels.SMS.enabled' => false]);

        $user = User::factory()->create();

        $this->dispatcher->dispatch($this->intent([$user], [
            'category' => NotificationCategory::INCIDENT,
            'priority' => NotificationPriority::CRITICAL,
        ]));

        $sms = NotificationDelivery::where('channel', 'SMS')->first();

        $this->assertSame(DeliveryStatus::SUPPRESSED, $sms->status);
        $this->assertStringContainsString('disabled', $sms->reason);
    }

    // ====================================================================
    // ROBUSTNESS — BR-408
    // ====================================================================

    #[Test]
    public function an_intent_with_no_recipients_dispatches_nothing(): void
    {
        $this->dispatcher->dispatch($this->intent([]));

        $this->assertDatabaseCount('notifications', 0);
    }

    #[Test]
    public function one_failing_recipient_does_not_cost_the_others_their_message(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Force a failure for one recipient by deleting them mid-flight: the
        // foreign key rejects the insert for Bob only.
        $bobId = $bob->id;
        $bob->forceDelete();
        $bob->id = $bobId;
        $bob->exists = true;

        $this->dispatcher->dispatch($this->intent([$alice, $bob]));

        $this->assertDatabaseHas('notifications', ['user_id' => $alice->id]);
    }

    // ====================================================================
    // CRITICAL DELIVERY
    // ====================================================================

    #[Test]
    public function a_critical_notification_reaches_every_addressable_channel(): void
    {
        config(['ctms.notifications.channels.SMS.enabled' => true]);

        $user = User::factory()->create(['phone_number' => '+919876500123']);
        NotificationDevice::factory()->create(['user_id' => $user->id]);

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::INCIDENT->value,
            'channels' => [],
            'muted' => true,
        ]);

        $this->dispatcher->dispatch($this->intent([$user->fresh()], [
            'category' => NotificationCategory::INCIDENT,
            'priority' => NotificationPriority::CRITICAL,
        ]));

        $notification = Notification::first();
        $attempted = $notification->deliveries()
            ->where('status', '!=', DeliveryStatus::SUPPRESSED->value)
            ->pluck('channel')
            ->map(fn ($channel) => $channel->value)
            ->all();

        $this->assertContains('PUSH', $attempted);
        $this->assertContains('SMS', $attempted);
        $this->assertContains('EMAIL', $attempted);
    }
}
