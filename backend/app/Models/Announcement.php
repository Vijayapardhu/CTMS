<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service announcement (blueprint §Communication).
 *
 * Three faults in the placeholder version, all of the kind that only show up
 * in use: `$fillable` named `created_by` while the column is `created_by_id`,
 * so attribution silently never saved; the relation pointed at the same
 * missing column; and `scopeForAudience()` chained an ungrouped `orWhere`,
 * which meant `active()->forAudience(...)` returned every ALL-audience
 * announcement including expired and withdrawn ones.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type.
 *
 * @property AnnouncementAudience $target_audience
 * @property AnnouncementPriority $priority
 */
class Announcement extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Empty: publication state and attribution are decided by the service,
     * never taken from a payload. A client that could set `published_at`
     * could publish in somebody else's name, backdated.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_audience' => AnnouncementAudience::class,
            'priority' => AnnouncementPriority::class,
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Live announcements: published, not withdrawn, not expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Announcements a given role should see.
     *
     * The `orWhere` is wrapped in a closure. Without that grouping it escapes
     * whatever came before it — so `active()->forRole(...)` would return
     * withdrawn and expired notices, which is how a cancelled service alert
     * stays on a student's screen.
     */
    public function scopeForRole(Builder $query, UserRole $role): Builder
    {
        $audiences = array_values(array_filter(
            AnnouncementAudience::cases(),
            fn (AnnouncementAudience $audience) => $audience->includes($role),
        ));

        return $query->whereIn(
            'target_audience',
            array_map(fn (AnnouncementAudience $a) => $a->value, $audiences),
        );
    }

    /**
     * Highest priority first, then newest.
     */
    public function scopeByImportance(Builder $query): Builder
    {
        return $query->orderByRaw(
            "CASE priority WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END"
        )->orderByDesc('published_at');
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null
            && $this->published_at->isPast()
            && $this->is_active;
    }

    /**
     * Whether it is currently visible to anybody.
     */
    public function isLive(): bool
    {
        return $this->isPublished() && ! $this->isExpired();
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->isLive() && $this->target_audience->includes($user->role);
    }
}
