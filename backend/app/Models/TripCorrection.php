<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-258 — a correction to a closed trip.
 *
 * The correction is a new, attributed record. The original value is copied
 * here before the trip is touched, so "what did it say before somebody
 * changed it" always has an answer. Nothing here is ever updated or deleted.
 */
class TripCorrection extends Model
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

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_id');
    }
}
