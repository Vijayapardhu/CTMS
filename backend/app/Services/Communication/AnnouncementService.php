<?php

namespace App\Services\Communication;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Events\Communication\AnnouncementPublished;
use App\Exceptions\BusinessRuleException;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Service announcements (blueprint §Communication).
 *
 * Drafting and publishing are separate acts. An announcement is written, read
 * back, and only then pushed to every student or driver on the system — a
 * single endpoint that did both would make a typo unrecallable, because the
 * notification has already gone.
 */
class AnnouncementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Draft an announcement. Nobody is told anything yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function draft(array $data, User $actor): Announcement
    {
        return DB::transaction(function () use ($data, $actor) {
            $announcement = new Announcement;

            $announcement->forceFill([
                'created_by_id' => $actor->getKey(),
                'title' => $data['title'],
                'content' => $data['content'],
                'target_audience' => $this->audience($data['target_audience'] ?? null),
                'priority' => $this->priority($data['priority'] ?? null),
                'expires_at' => $data['expires_at'] ?? null,
                // Explicitly unpublished. Publication is its own decision.
                'published_at' => null,
                'is_active' => true,
            ])->save();

            $this->audit->created($announcement, $actor);

            return $announcement;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function update(Announcement $announcement, array $data, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $data, $actor) {
            $announcement = $this->lock($announcement);

            // Editing after publication would rewrite a message people have
            // already read and acted on. Withdraw it and issue a new one.
            if ($announcement->isPublished()) {
                throw new BusinessRuleException(
                    'This announcement has already been published and cannot be edited. '
                    .'Withdraw it and publish a correction.',
                );
            }

            $before = $announcement->getAttributes();

            $announcement->forceFill(array_filter([
                'title' => $data['title'] ?? null,
                'content' => $data['content'] ?? null,
                'target_audience' => isset($data['target_audience'])
                    ? $this->audience($data['target_audience']) : null,
                'priority' => isset($data['priority'])
                    ? $this->priority($data['priority']) : null,
                'expires_at' => $data['expires_at'] ?? null,
            ], fn ($value) => $value !== null))->save();

            $this->audit->updated($announcement, $before, $actor);

            return $announcement;
        });
    }

    /**
     * Publish it, and tell the audience.
     *
     * @throws BusinessRuleException
     */
    public function publish(Announcement $announcement, User $actor): Announcement
    {
        $announcement = DB::transaction(function () use ($announcement, $actor) {
            $announcement = $this->lock($announcement);

            if ($announcement->published_at !== null) {
                throw new BusinessRuleException('This announcement has already been published.');
            }

            if ($announcement->isExpired()) {
                throw new BusinessRuleException(
                    'This announcement expired before it was published. Change the expiry date first.',
                );
            }

            $announcement->forceFill([
                'published_at' => now(),
                'is_active' => true,
            ])->save();

            $this->audit->log(
                action: 'ANNOUNCEMENT_PUBLISHED',
                table: $announcement->getTable(),
                recordId: (string) $announcement->getKey(),
                new: [
                    'title' => $announcement->title,
                    'audience' => $announcement->target_audience->value,
                    'priority' => $announcement->priority->value,
                ],
                actor: $actor,
            );

            return $announcement;
        });

        // Published outside the transaction, like every other publisher here:
        // a notification failure must not roll back the publication (BR-408).
        AnnouncementPublished::dispatch($announcement->fresh());

        return $announcement;
    }

    /**
     * Take it down. The record survives; only its visibility ends.
     *
     * @throws BusinessRuleException
     */
    public function withdraw(Announcement $announcement, string $reason, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $reason, $actor) {
            $announcement = $this->lock($announcement);

            if (! $announcement->is_active) {
                throw new BusinessRuleException('This announcement has already been withdrawn.');
            }

            $announcement->forceFill(['is_active' => false])->save();

            // Withdrawing does not un-send the notification that already went
            // out, so what actually happened stays on the record.
            $this->audit->log(
                action: 'ANNOUNCEMENT_WITHDRAWN',
                table: $announcement->getTable(),
                recordId: (string) $announcement->getKey(),
                new: ['reason' => $reason],
                actor: $actor,
            );

            return $announcement;
        });
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    private function lock(Announcement $announcement): Announcement
    {
        return Announcement::whereKey($announcement->getKey())->lockForUpdate()->firstOrFail();
    }

    private function audience(mixed $value): AnnouncementAudience
    {
        if ($value instanceof AnnouncementAudience) {
            return $value;
        }

        return $value === null
            ? AnnouncementAudience::ALL
            : AnnouncementAudience::from(strtoupper((string) $value));
    }

    private function priority(mixed $value): AnnouncementPriority
    {
        if ($value instanceof AnnouncementPriority) {
            return $value;
        }

        return $value === null
            ? AnnouncementPriority::MEDIUM
            : AnnouncementPriority::from(strtoupper((string) $value));
    }
}
