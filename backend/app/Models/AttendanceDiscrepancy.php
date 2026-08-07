<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-266 — the headcount and the boarding events disagree.
 *
 * Both numbers are kept. The system does not pick a winner, because it has no
 * way of knowing which is right, and quietly overwriting one with the other
 * would destroy the only evidence that a child may be unaccounted for. A human
 * reviews it; the record of the disagreement survives the review.
 */
class AttendanceDiscrepancy extends Model
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
            'headcount' => 'integer',
            'boarding_event_count' => 'integer',
            'difference' => 'integer',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'OPEN');
    }

    /**
     * More people counted than accounted for — the direction that matters
     * most, because it means somebody is on the bus who is not on any list.
     */
    public function isUnderAccounted(): bool
    {
        return $this->difference > 0;
    }
}
