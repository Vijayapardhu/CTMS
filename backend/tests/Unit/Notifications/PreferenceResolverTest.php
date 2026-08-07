<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Models\NotificationDevice;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-402, BR-403, BR-404 — deciding whether to bother somebody.
 *
 * The whole "should we send this?" question lives in one class, so these
 * tests are where the rules are actually pinned down.
 */
class PreferenceResolverTest extends TestCase
{
    use RefreshDatabase;

    private PreferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(PreferenceResolver::class);

        config([
            'ctms.notifications.channels.PUSH.enabled' => true,
            'ctms.notifications.channels.EMAIL.enabled' => true,
            'ctms.notifications.channels.SMS.enabled' => true,
        ]);
    }

    private function userWithDevice(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        NotificationDevice::factory()->create(['user_id' => $user->id]);

        return $user->fresh();
    }

    /**
     * @return array<int, string>
     */
    private function channelValues(array $resolution): array
    {
        return array_map(fn (NotificationChannel $channel) => $channel->value, $resolution['channels']);
    }

    // ====================================================================
    // DEFAULTS
    // ====================================================================

    #[Test]
    public function a_user_with_no_preferences_receives_the_category_defaults(): void
    {
        $user = $this->userWithDevice();

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertContains('PUSH', $this->channelValues($resolution));
        $this->assertContains('IN_APP', $this->channelValues($resolution));
    }

    #[Test]
    public function in_app_is_always_delivered(): void
    {
        $user = User::factory()->create();

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::TRIP->value,
            'channels' => [],
            'muted' => true,
        ]);

        // The notification centre is the record of what the system said. A
        // muted push must not erase the history.
        $resolution = $this->resolver->resolve(
            $user->fresh(), NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertContains('IN_APP', $this->channelValues($resolution));
    }

    // ====================================================================
    // MUTING — BR-403, BR-404
    // ====================================================================

    #[Test]
    public function a_muted_category_suppresses_its_channels(): void
    {
        $user = $this->userWithDevice();

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::TRIP->value,
            'channels' => ['PUSH'],
            'muted' => true,
        ]);

        $resolution = $this->resolver->resolve(
            $user->fresh(), NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertNotContains('PUSH', $this->channelValues($resolution));
        $this->assertSame(
            'The recipient has muted this category.',
            $resolution['suppressed']['PUSH'],
        );
    }

    #[Test]
    public function a_non_mutable_category_ignores_a_stale_mute(): void
    {
        $user = $this->userWithDevice();

        // BR-404 — the category wins over a preference row that should never
        // have been able to say this.
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::INCIDENT->value,
            'channels' => ['PUSH'],
            'muted' => true,
        ]);

        $resolution = $this->resolver->resolve(
            $user->fresh(), NotificationCategory::INCIDENT, NotificationPriority::STANDARD,
        );

        $this->assertContains('PUSH', $this->channelValues($resolution));
    }

    #[Test]
    public function an_unselected_channel_is_suppressed(): void
    {
        $user = $this->userWithDevice();

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::TRIP->value,
            'channels' => ['IN_APP'],
            'muted' => false,
        ]);

        $resolution = $this->resolver->resolve(
            $user->fresh(), NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertNotContains('PUSH', $this->channelValues($resolution));
        $this->assertArrayHasKey('PUSH', $resolution['suppressed']);
    }

    // ====================================================================
    // CRITICAL OVERRIDE — BR-402
    // ====================================================================

    #[Test]
    public function a_critical_notification_ignores_muting(): void
    {
        $user = $this->userWithDevice();

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'category' => NotificationCategory::TRIP->value,
            'channels' => [],
            'muted' => true,
        ]);

        $resolution = $this->resolver->resolve(
            $user->fresh(), NotificationCategory::TRIP, NotificationPriority::CRITICAL,
        );

        $this->assertContains('PUSH', $this->channelValues($resolution));
        $this->assertContains('EMAIL', $this->channelValues($resolution));
    }

    #[Test]
    public function a_critical_notification_ignores_quiet_hours(): void
    {
        $user = $this->userWithDevice([
            'quiet_hours_start' => now()->subHour()->format('H:i:s'),
            'quiet_hours_end' => now()->addHour()->format('H:i:s'),
        ]);

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::INCIDENT, NotificationPriority::CRITICAL,
        );

        $this->assertContains('PUSH', $this->channelValues($resolution));
    }

    #[Test]
    public function a_critical_notification_still_needs_an_address(): void
    {
        // Overriding preferences cannot conjure a device that is not there.
        $user = User::factory()->create(['phone_number' => null]);

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::INCIDENT, NotificationPriority::CRITICAL,
        );

        $this->assertNotContains('PUSH', $this->channelValues($resolution));
        $this->assertSame(
            'No delivery address registered for this channel.',
            $resolution['suppressed']['PUSH'],
        );
    }

    // ====================================================================
    // QUIET HOURS
    // ====================================================================

    #[Test]
    public function standard_notifications_are_suppressed_during_quiet_hours(): void
    {
        $user = $this->userWithDevice([
            'quiet_hours_start' => now()->subHour()->format('H:i:s'),
            'quiet_hours_end' => now()->addHour()->format('H:i:s'),
        ]);

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertSame(
            "Within the recipient's quiet hours.",
            $resolution['suppressed']['PUSH'],
        );
    }

    #[Test]
    public function quiet_hours_that_wrap_midnight_are_handled(): void
    {
        // 22:00 to 07:00 is one window, not two.
        $this->travelTo(now()->setTime(23, 30));

        $user = $this->userWithDevice([
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
        ]);

        $this->assertTrue($this->resolver->isWithinQuietHours($user));

        $this->travelTo(now()->setTime(2, 0));
        $this->assertTrue($this->resolver->isWithinQuietHours($user));

        $this->travelTo(now()->setTime(12, 0));
        $this->assertFalse($this->resolver->isWithinQuietHours($user));

        $this->travelBack();
    }

    #[Test]
    public function outside_quiet_hours_nothing_is_suppressed_for_them(): void
    {
        $this->travelTo(now()->setTime(12, 0));

        $user = $this->userWithDevice([
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
        ]);

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertContains('PUSH', $this->channelValues($resolution));

        $this->travelBack();
    }

    #[Test]
    public function a_zero_length_quiet_window_silences_nothing(): void
    {
        $user = $this->userWithDevice([
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '22:00:00',
        ]);

        $this->assertFalse($this->resolver->isWithinQuietHours($user));
    }

    // ====================================================================
    // CHANNEL AVAILABILITY
    // ====================================================================

    #[Test]
    public function a_disabled_channel_is_suppressed_for_everyone(): void
    {
        config(['ctms.notifications.channels.SMS.enabled' => false]);

        $user = $this->userWithDevice();

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::INCIDENT, NotificationPriority::CRITICAL,
        );

        $this->assertNotContains('SMS', $this->channelValues($resolution));
        $this->assertSame(
            'Channel is disabled for this installation.',
            $resolution['suppressed']['SMS'],
        );
    }

    #[Test]
    public function a_user_with_no_device_cannot_receive_push(): void
    {
        $user = User::factory()->create();

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertNotContains('PUSH', $this->channelValues($resolution));
    }

    #[Test]
    public function a_revoked_device_does_not_count_as_an_address(): void
    {
        $user = User::factory()->create();
        $device = NotificationDevice::factory()->create(['user_id' => $user->id]);
        $device->revoke('Test');

        $resolution = $this->resolver->resolve(
            $user->fresh(), NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        $this->assertNotContains('PUSH', $this->channelValues($resolution));
    }

    #[Test]
    public function every_channel_is_accounted_for(): void
    {
        $user = $this->userWithDevice();

        $resolution = $this->resolver->resolve(
            $user, NotificationCategory::TRIP, NotificationPriority::STANDARD,
        );

        // Each channel is either attempted or has a recorded reason it was
        // not. Nothing is silently dropped (BR-407).
        $accounted = count($resolution['channels']) + count($resolution['suppressed']);

        $this->assertSame(count(NotificationChannel::cases()), $accounted);
    }
}
