<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-501 — a record of somebody *reading* a student's personal data.
 *
 * Writes are covered by the audit trail. Reads are not, and reads are the ones
 * that matter here: "who looked at this child's location history" is the
 * question asked when something has gone wrong, and a write-only audit trail
 * cannot answer it.
 *
 * Append-only, like the audit trail, and for the same reason.
 */
class DataAccessLog extends Model
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
            'is_bulk' => 'boolean',
            'record_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at ??= $model->freshTimestamp();
        });

        static::updating(function () {
            throw new BusinessRuleException(
                'Data access records are append-only and cannot be modified.',
            );
        });

        static::deleting(function () {
            throw new BusinessRuleException(
                'Data access records are append-only and cannot be deleted.',
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * BR-502 — bulk exports, which are reviewed separately from ordinary
     * record-by-record access.
     */
    public function scopeBulk(Builder $query): Builder
    {
        return $query->where('is_bulk', true);
    }

    public function scopeForSubject(Builder $query, string $type, string $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }
}
