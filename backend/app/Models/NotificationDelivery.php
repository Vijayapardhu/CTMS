<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt sequence to reach one person on one channel (BR-407).
 *
 * Every outcome is recorded, including deliberate non-delivery: a suppressed
 * notification is a fact worth keeping, because "why didn't I get told?" is
 * otherwise unanswerable.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property NotificationChannel $channel
 * @property DeliveryStatus $status
 */
class NotificationDelivery extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'first_attempted_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function escalatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'escalated_from_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::RETRYING->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now());
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::PERMANENTLY_FAILED->value);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function markSent(?string $providerReference = null): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::DELIVERED,
            'delivered_at' => now(),
            'provider_reference' => $providerReference,
            'next_attempt_at' => null,
            'reason' => null,
        ])->save();
    }

    public function markSuppressed(string $reason): void
    {
        $this->forceFill([
            'status' => DeliveryStatus::SUPPRESSED,
            'reason' => $reason,
            'next_attempt_at' => null,
        ])->save();
    }
}
