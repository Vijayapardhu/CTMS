<?php

namespace Tests\Feature\Communication;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Models\Announcement;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Service announcements (blueprint §Communication).
 *
 * The model and table existed from the start with no service, controller or
 * route behind them — and three faults that only show up in use: `$fillable`
 * named a column that does not exist, the `createdBy` relation pointed at the
 * same missing column, and an ungrouped `orWhere` in the audience scope leaked
 * withdrawn and expired notices.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    // ====================================================================
    // DRAFT AND PUBLISH ARE SEPARATE ACTS
    // ====================================================================

    #[Test]
    public function drafting_tells_nobody(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->postJson('/api/v1/announcements', [
            'title' => 'Route 7 diversion next week',
            'content' => 'Roadworks on Main Street from Monday. Expect a five minute delay.',
        ], $this->authHeader($admin))->assertStatus(201);

        // A single endpoint that drafted and published would make a typo
        // unrecallable, because the notification has already gone.
        $this->assertNull(Announcement::first()->published_at);
        $this->assertSame(0, Notification::where('user_id', $student->id)->count());
    }

    #[Test]
    public function publishing_reaches_the_target_audience(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $driver = $this->createDriver();

        $announcement = Announcement::factory()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::STUDENTS->value,
        ]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'announcement.published')->count());

        // A notice addressed to students does not reach drivers.
        $this->assertSame(0, Notification::where('user_id', $driver->id)
            ->where('event_key', 'announcement.published')->count());
    }

    #[Test]
    public function an_all_audience_notice_reaches_everyone(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $driver = $this->createDriver();

        $announcement = Announcement::factory()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::ALL->value,
        ]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($admin))->assertOk();

        foreach ([$student, $driver, $admin] as $user) {
            $this->assertSame(1, Notification::where('user_id', $user->id)
                ->where('event_key', 'announcement.published')->count());
        }
    }

    #[Test]
    public function an_announcement_is_never_delivered_as_critical(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $announcement = Announcement::factory()->create([
            'created_by_id' => $admin->id,
            'priority' => AnnouncementPriority::HIGH->value,
        ]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($admin))->assertOk();

        // CRITICAL bypasses quiet hours and mute (BR-402). That exemption
        // belongs to a child in danger, not to the notice board — however
        // urgent whoever wrote it believed it was.
        $this->assertSame('STANDARD', Notification::where('user_id', $student->id)
            ->where('event_key', 'announcement.published')->first()->priority->value);
    }

    #[Test]
    public function publishing_twice_does_not_message_people_twice(): void
    {
        $admin = $this->createAdmin();
        $announcement = Announcement::factory()->create(['created_by_id' => $admin->id]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_published_announcement_cannot_be_edited(): void
    {
        $admin = $this->createAdmin();
        $announcement = Announcement::factory()->published()->create(['created_by_id' => $admin->id]);

        // Editing after publication rewrites a message people have already
        // read and acted on.
        $this->putJson("/api/v1/announcements/{$announcement->id}", [
            'title' => 'Actually, never mind',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_draft_can_be_edited(): void
    {
        $admin = $this->createAdmin();
        $announcement = Announcement::factory()->create(['created_by_id' => $admin->id]);

        $this->putJson("/api/v1/announcements/{$announcement->id}", [
            'title' => 'Corrected before anyone saw it',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame('Corrected before anyone saw it', $announcement->fresh()->title);
    }

    #[Test]
    public function an_announcement_that_expired_before_publication_is_refused(): void
    {
        $admin = $this->createAdmin();

        $announcement = Announcement::factory()->create([
            'created_by_id' => $admin->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function an_expiry_in_the_past_is_refused_at_the_edge(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/announcements', [
            'title' => 'Already stale',
            'content' => 'This notice would be invisible the moment it was published.',
            'expires_at' => now()->subDay()->toIso8601String(),
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['expires_at']]);
    }

    // ====================================================================
    // WITHDRAWAL
    // ====================================================================

    #[Test]
    public function withdrawing_requires_a_reason(): void
    {
        $admin = $this->createAdmin();
        $announcement = Announcement::factory()->published()->create(['created_by_id' => $admin->id]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/withdraw", [],
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function a_withdrawn_announcement_disappears_from_the_board(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $announcement = Announcement::factory()->published()->create(['created_by_id' => $admin->id]);

        $this->getJson('/api/v1/announcements', $this->authHeader($student))
            ->assertOk()->assertJsonPath('pagination.total', 1);

        $this->postJson("/api/v1/announcements/{$announcement->id}/withdraw", [
            'reason' => 'Superseded by a corrected notice.',
        ], $this->authHeader($admin))->assertOk();

        $this->getJson('/api/v1/announcements', $this->authHeader($student))
            ->assertOk()->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function withdrawing_is_recorded_rather_than_erased(): void
    {
        $admin = $this->createAdmin();
        $announcement = Announcement::factory()->published()->create(['created_by_id' => $admin->id]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/withdraw", [
            'reason' => 'Wrong dates given for the diversion.',
        ], $this->authHeader($admin))->assertOk();

        // Withdrawing does not un-send what already went out, so what happened
        // stays on the record.
        $this->assertDatabaseHas('announcements', ['id' => $announcement->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ANNOUNCEMENT_WITHDRAWN',
            'record_id' => $announcement->id,
        ]);
    }

    // ====================================================================
    // THE BOARD — SCOPING AND THE orWhere BUG
    // ====================================================================

    #[Test]
    public function a_student_sees_only_live_notices_for_their_audience(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        Announcement::factory()->published()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::STUDENTS->value,
        ]);
        Announcement::factory()->published()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::DRIVERS->value,
        ]);
        Announcement::factory()->create(['created_by_id' => $admin->id]); // draft

        $this->getJson('/api/v1/announcements', $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function an_expired_all_audience_notice_does_not_leak_through_the_scope(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        Announcement::factory()->expired()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::ALL->value,
        ]);

        // The original scope chained `where(audience)->orWhere(audience=all)`
        // without grouping, so the `orWhere` escaped the active() constraint
        // and every ALL-audience notice came back regardless of whether it had
        // expired or been withdrawn.
        $this->getJson('/api/v1/announcements', $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function a_withdrawn_all_audience_notice_does_not_leak_either(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        Announcement::factory()->withdrawn()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::ALL->value,
        ]);

        $this->getJson('/api/v1/announcements', $this->authHeader($student))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function high_priority_notices_sort_to_the_top(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        Announcement::factory()->published()->create([
            'created_by_id' => $admin->id,
            'priority' => AnnouncementPriority::LOW->value,
        ]);
        Announcement::factory()->published()->create([
            'created_by_id' => $admin->id,
            'priority' => AnnouncementPriority::HIGH->value,
        ]);

        $response = $this->getJson('/api/v1/announcements', $this->authHeader($student))->assertOk();

        $this->assertSame('HIGH', $response->json('data.0.priority'));
    }

    #[Test]
    public function operations_can_see_its_own_drafts(): void
    {
        $admin = $this->createAdmin();

        Announcement::factory()->create(['created_by_id' => $admin->id]);

        $this->getJson('/api/v1/announcements?include_drafts=1', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    // ====================================================================
    // ATTRIBUTION AND AUTHORIZATION
    // ====================================================================

    #[Test]
    public function attribution_is_actually_saved(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/announcements', [
            'title' => 'Fleet notice',
            'content' => 'All drivers to collect updated route cards from the office.',
        ], $this->authHeader($admin))->assertStatus(201);

        $announcement = Announcement::first();

        // `$fillable` named `created_by` while the column is `created_by_id`,
        // so attribution silently never saved and the relation always came
        // back null.
        $this->assertSame((string) $admin->id, (string) $announcement->created_by_id);
        $this->assertTrue($announcement->createdBy->is($admin));
    }

    #[Test]
    public function a_client_cannot_publish_by_payload(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/announcements', [
            'title' => 'Trying to self-publish',
            'content' => 'This payload attempts to set its own publication state.',
            'published_at' => now()->subYear()->toIso8601String(),
            'is_active' => true,
            'created_by_id' => $this->createStudent()->id,
        ], $this->authHeader($admin))->assertStatus(201);

        $announcement = Announcement::first();

        $this->assertNull($announcement->published_at);
        $this->assertSame((string) $admin->id, (string) $announcement->created_by_id);
    }

    #[Test]
    public function a_student_cannot_write_an_announcement(): void
    {
        $student = $this->createStudent();

        $this->postJson('/api/v1/announcements', [
            'title' => 'Class is cancelled',
            'content' => 'A rider should not be able to tell the whole fleet anything.',
        ], $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function a_driver_cannot_publish_an_announcement(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $announcement = Announcement::factory()->create(['created_by_id' => $admin->id]);

        $this->postJson("/api/v1/announcements/{$announcement->id}/publish", [],
            $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_rider_cannot_read_a_draft_by_guessing_its_id(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();
        $announcement = Announcement::factory()->create(['created_by_id' => $admin->id]);

        $this->getJson("/api/v1/announcements/{$announcement->id}", $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function a_rider_cannot_read_another_audiences_notice(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $announcement = Announcement::factory()->published()->create([
            'created_by_id' => $admin->id,
            'target_audience' => AnnouncementAudience::DRIVERS->value,
        ]);

        $this->getJson("/api/v1/announcements/{$announcement->id}", $this->authHeader($student))
            ->assertStatus(403);
    }

    #[Test]
    public function announcements_require_authentication(): void
    {
        $this->getJson('/api/v1/announcements')->assertStatus(401);
    }
}
