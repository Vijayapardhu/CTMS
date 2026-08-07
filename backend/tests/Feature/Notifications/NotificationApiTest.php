<?php

namespace Tests\Feature\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Models\Notification;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Notifications\NotificationIntent;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SH-14, SH-15, AD-94 — the notification API.
 *
 * BR-400 makes the recipient the only reader, so the horizontal-access cases
 * matter more here than anywhere except student records.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, array $overrides = []): Notification
    {
        app(NotificationDispatcher::class)->dispatch(NotificationIntent::make(
            eventKey: $overrides['eventKey'] ?? 'test.event',
            category: $overrides['category'] ?? NotificationCategory::TRIP,
            recipients: [$user],
            title: $overrides['title'] ?? 'Your bus has departed',
            body: 'Route 7 left at 07:15.',
            priority: $overrides['priority'] ?? NotificationPriority::STANDARD,
            dedupKey: $overrides['dedupKey'] ?? uniqid('key', true),
        ));

        return Notification::where('user_id', $user->id)->latest('created_at')->firstOrFail();
    }

    // ====================================================================
    // NOTIFICATION CENTRE — SH-15
    // ====================================================================

    #[Test]
    public function a_user_can_list_their_notifications(): void
    {
        $user = $this->createStudent();
        $this->notify($user);
        $this->notify($user);

        $this->getJson('/api/v1/notifications', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    #[Test]
    public function a_user_never_sees_another_users_notifications(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();
        $this->notify($bob);

        $this->getJson('/api/v1/notifications', $this->authHeader($alice))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function an_admin_cannot_read_another_users_notification(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $notification = $this->notify($student);

        // There is no privilege level at which one person reads another's
        // notifications; the delivery log exists for operational visibility.
        $this->getJson("/api/v1/notifications/{$notification->id}", $this->authHeader($admin))
            ->assertStatus(404);
    }

    #[Test]
    public function listing_notifications_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    #[Test]
    public function notifications_can_be_filtered_to_unread(): void
    {
        $user = $this->createStudent();
        $read = $this->notify($user);
        $this->notify($user);

        $read->markRead();

        $this->getJson('/api/v1/notifications?unread=1', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function notifications_can_be_filtered_by_category(): void
    {
        $user = $this->createStudent();
        $this->notify($user, ['category' => NotificationCategory::TRIP]);
        $this->notify($user, ['category' => NotificationCategory::FINANCE]);

        $this->getJson('/api/v1/notifications?category=FINANCE', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function an_unknown_category_filter_is_rejected(): void
    {
        $user = $this->createStudent();

        $this->getJson('/api/v1/notifications?category=GOSSIP', $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['category']]);
    }

    #[Test]
    public function the_unread_count_drives_the_badge(): void
    {
        $user = $this->createStudent();
        $this->notify($user);
        $this->notify($user);

        $this->getJson('/api/v1/notifications/unread-count', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.unread', 2);
    }

    #[Test]
    public function a_notification_can_be_marked_read(): void
    {
        $user = $this->createStudent();
        $notification = $this->notify($user);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read", [], $this->authHeader($user))
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Test]
    public function marking_read_twice_does_not_move_the_timestamp(): void
    {
        $user = $this->createStudent();
        $notification = $this->notify($user);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read", [], $this->authHeader($user));
        $first = $notification->fresh()->read_at;

        $this->travel(1)->minutes();

        $this->patchJson("/api/v1/notifications/{$notification->id}/read", [], $this->authHeader($user));

        $this->assertEquals($first, $notification->fresh()->read_at);
    }

    #[Test]
    public function a_notification_can_be_marked_unread_again(): void
    {
        $user = $this->createStudent();
        $notification = $this->notify($user);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read", [], $this->authHeader($user));
        $this->patchJson("/api/v1/notifications/{$notification->id}/unread", [], $this->authHeader($user))
            ->assertOk();

        $this->assertNull($notification->fresh()->read_at);
    }

    #[Test]
    public function all_notifications_can_be_marked_read_at_once(): void
    {
        $user = $this->createStudent();
        $this->notify($user);
        $this->notify($user);

        $this->postJson('/api/v1/notifications/read-all', [], $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.marked', 2);

        $this->assertSame(0, Notification::forUser($user->id)->unread()->count());
    }

    #[Test]
    public function marking_all_read_does_not_touch_another_user(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();
        $this->notify($alice);
        $bobs = $this->notify($bob);

        $this->postJson('/api/v1/notifications/read-all', [], $this->authHeader($alice))->assertOk();

        $this->assertNull($bobs->fresh()->read_at);
    }

    #[Test]
    public function a_user_can_delete_their_own_notification(): void
    {
        $user = $this->createStudent();
        $notification = $this->notify($user);

        $this->deleteJson("/api/v1/notifications/{$notification->id}", [], $this->authHeader($user))
            ->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    #[Test]
    public function a_user_cannot_delete_another_users_notification(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();
        $notification = $this->notify($bob);

        $this->deleteJson("/api/v1/notifications/{$notification->id}", [], $this->authHeader($alice))
            ->assertStatus(404);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    // ====================================================================
    // PREFERENCES — SH-14
    // ====================================================================

    #[Test]
    public function preferences_return_every_category_with_its_defaults(): void
    {
        $user = $this->createStudent();

        $response = $this->getJson('/api/v1/notification-preferences', $this->authHeader($user))
            ->assertOk();

        // A settings screen must show the real current state, not an empty list.
        $this->assertCount(count(NotificationCategory::cases()), $response->json('data.categories'));
    }

    #[Test]
    public function locked_categories_are_flagged_with_a_reason(): void
    {
        $user = $this->createStudent();

        $response = $this->getJson('/api/v1/notification-preferences', $this->authHeader($user))
            ->assertOk();

        $incident = collect($response->json('data.categories'))
            ->firstWhere('category', NotificationCategory::INCIDENT->value);

        // BR-404 — shown as locked with an explanation, not hidden.
        $this->assertFalse($incident['mutable']);
        $this->assertNotNull($incident['locked_reason']);
    }

    #[Test]
    public function a_user_can_change_their_preferences(): void
    {
        $user = $this->createStudent();

        $this->putJson('/api/v1/notification-preferences', [
            'categories' => [
                ['category' => 'TRIP', 'channels' => ['IN_APP'], 'muted' => true],
            ],
        ], $this->authHeader($user))->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'TRIP',
            'muted' => true,
        ]);
    }

    #[Test]
    public function a_safety_category_cannot_be_muted(): void
    {
        $user = $this->createStudent();

        $this->putJson('/api/v1/notification-preferences', [
            'categories' => [
                ['category' => 'INCIDENT', 'channels' => [], 'muted' => true],
            ],
        ], $this->authHeader($user))->assertOk();

        // BR-404 — the setting is simply not applied.
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'INCIDENT',
            'muted' => false,
        ]);
    }

    #[Test]
    public function quiet_hours_can_be_set(): void
    {
        $user = $this->createStudent();

        $this->putJson('/api/v1/notification-preferences', [
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
        ], $this->authHeader($user))->assertOk();

        $this->assertSame('22:00:00', $user->fresh()->quiet_hours_start);
    }

    #[Test]
    public function quiet_hours_need_both_ends(): void
    {
        $user = $this->createStudent();

        $this->putJson('/api/v1/notification-preferences', [
            'quiet_hours' => ['start' => '22:00:00'],
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['quiet_hours.end']]);
    }

    #[Test]
    public function an_unknown_channel_is_rejected(): void
    {
        $user = $this->createStudent();

        $this->putJson('/api/v1/notification-preferences', [
            'categories' => [
                ['category' => 'TRIP', 'channels' => ['CARRIER_PIGEON']],
            ],
        ], $this->authHeader($user))->assertStatus(422);
    }

    // ====================================================================
    // DEVICES
    // ====================================================================

    #[Test]
    public function a_user_can_register_a_device(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/notification-devices', [
            'token' => 'tok_abcdefghijklmnopqrstuvwxyz012345',
            'platform' => 'ANDROID',
            'device_name' => 'Pixel 8',
        ], $this->authHeader($user))->assertOk();

        $this->assertDatabaseHas('notification_devices', [
            'user_id' => $user->id,
            'platform' => 'ANDROID',
        ]);
    }

    #[Test]
    public function registering_the_same_token_twice_does_not_duplicate(): void
    {
        $user = $this->createStudent();
        $payload = [
            'token' => 'tok_abcdefghijklmnopqrstuvwxyz012345',
            'platform' => 'IOS',
        ];

        // Clients re-register on every launch; that must not accumulate rows.
        $this->postJson('/api/v1/notification-devices', $payload, $this->authHeader($user))->assertOk();
        $this->postJson('/api/v1/notification-devices', $payload, $this->authHeader($user))->assertOk();

        $this->assertDatabaseCount('notification_devices', 1);
    }

    #[Test]
    public function a_token_reassigned_to_another_account_moves_rather_than_duplicating(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();
        $payload = [
            'token' => 'tok_abcdefghijklmnopqrstuvwxyz012345',
            'platform' => 'ANDROID',
        ];

        $this->postJson('/api/v1/notification-devices', $payload, $this->authHeader($alice))->assertOk();
        $this->postJson('/api/v1/notification-devices', $payload, $this->authHeader($bob))->assertOk();

        // Leaving it on the old account would send one family's
        // child-boarding notifications to a stranger's phone.
        $this->assertDatabaseCount('notification_devices', 1);
        $this->assertDatabaseHas('notification_devices', ['user_id' => $bob->id]);
        $this->assertDatabaseMissing('notification_devices', ['user_id' => $alice->id]);
    }

    #[Test]
    public function the_push_token_is_never_returned(): void
    {
        $user = $this->createStudent();
        $token = 'tok_abcdefghijklmnopqrstuvwxyz012345';

        $response = $this->postJson('/api/v1/notification-devices', [
            'token' => $token,
            'platform' => 'WEB',
        ], $this->authHeader($user))->assertOk();

        $this->assertStringNotContainsString($token, $response->getContent());
    }

    #[Test]
    public function device_registration_is_validated(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/notification-devices', [
            'token' => 'short',
            'platform' => 'SMOKE_SIGNAL',
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['token', 'platform']]);
    }

    #[Test]
    public function a_user_can_revoke_a_device(): void
    {
        $user = $this->createStudent();
        $device = NotificationDevice::factory()->create(['user_id' => $user->id]);

        $this->deleteJson("/api/v1/notification-devices/{$device->id}", [], $this->authHeader($user))
            ->assertOk();

        $this->assertNotNull($device->fresh()->revoked_at);
    }

    #[Test]
    public function a_user_cannot_revoke_another_users_device(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();
        $device = NotificationDevice::factory()->create(['user_id' => $bob->id]);

        $this->deleteJson("/api/v1/notification-devices/{$device->id}", [], $this->authHeader($alice))
            ->assertStatus(404);

        $this->assertNull($device->fresh()->revoked_at);
    }

    #[Test]
    public function all_devices_can_be_revoked_at_once(): void
    {
        $user = $this->createStudent();
        NotificationDevice::factory()->count(3)->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/notification-devices/revoke-all', [], $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.revoked', 3);

        $this->assertSame(0, NotificationDevice::where('user_id', $user->id)->active()->count());
    }

    #[Test]
    public function revoked_devices_are_not_listed(): void
    {
        $user = $this->createStudent();
        NotificationDevice::factory()->create(['user_id' => $user->id]);
        NotificationDevice::factory()->revoked()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/notification-devices', $this->authHeader($user))->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    // ====================================================================
    // DELIVERY LOG — AD-94
    // ====================================================================

    #[Test]
    public function an_admin_can_read_the_delivery_log(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $this->notify($student);

        $this->getJson('/api/v1/notification-log', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination']);
    }

    #[Test]
    public function a_student_cannot_read_the_delivery_log(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/notification-log', $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_read_the_delivery_log(): void
    {
        $driver = $this->createDriver();

        $this->getJson('/api/v1/notification-log', $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function channel_health_is_reported(): void
    {
        $admin = $this->createAdmin();
        $this->notify($this->createStudent());

        $response = $this->getJson('/api/v1/notification-log/health', $this->authHeader($admin))
            ->assertOk();

        // A failing channel is an operational incident, not a statistic.
        $this->assertCount(4, $response->json('data.channels'));
        $this->assertArrayHasKey('success_rate', $response->json('data.channels.0'));
    }

    #[Test]
    public function a_delivered_notification_cannot_be_resent(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $notification = $this->notify($student);

        $delivered = $notification->deliveries()->where('channel', 'IN_APP')->first();

        // Replaying a delivered message would tell somebody the same thing
        // twice, which BR-405 exists to prevent.
        $this->postJson("/api/v1/notification-log/{$delivered->id}/resend", [], $this->authHeader($admin))
            ->assertStatus(409);
    }

    #[Test]
    public function a_failed_delivery_can_be_replayed(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $notification = $this->notify($student);

        $delivery = $notification->deliveries()->where('channel', 'IN_APP')->first();
        $delivery->forceFill([
            'status' => DeliveryStatus::PERMANENTLY_FAILED,
            'reason' => 'Provider outage',
        ])->save();

        $this->postJson("/api/v1/notification-log/{$delivery->id}/resend", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertNotSame(
            DeliveryStatus::PERMANENTLY_FAILED,
            $delivery->fresh()->status,
        );
    }

    #[Test]
    public function resending_an_unknown_delivery_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/notification-log/019fd73c-0000-7000-8000-000000000000/resend', [],
            $this->authHeader($admin))->assertStatus(404);
    }
}
