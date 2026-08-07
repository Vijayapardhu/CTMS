<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the system told one person.
 *
 * The notification is the message; {@see NotificationDelivery} records each
 * attempt to get it to them. One notification, many deliveries — because
 * "we sent it" and "they received it" are different claims.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property NotificationCategory $category
 * @property NotificationPriority $priority
 */
class Notification extends Model
{
    use HasFactory, HasUuids;

    /**
     * Constructed by NotificationDispatcher, never mass-assigned from a
     * request: nothing outside the platform decides who gets told what.
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
            'category' => NotificationCategory::class,
            'priority' => NotificationPriority::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->isRead()) {
            return; // Idempotent: re-reading must not move the timestamp.
        }

        $this->forceFill(['read_at' => now()])->save();
    }

    public function markUnread(): void
    {
        $this->forceFill(['read_at' => null])->save();
    }

    /**
     * Whether any channel actually got through.
     */
    public function wasDelivered(): bool
    {
        return $this->deliveries()
            ->whereIn('status', [DeliveryStatus::DELIVERED->value, DeliveryStatus::SENT->value])
            ->exists();
    }
}
