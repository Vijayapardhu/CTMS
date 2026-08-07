<?php

namespace App\Models;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'table_name',
        'record_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Indicates if the model should be timestamped.
     * AuditLog only has created_at (no updated_at).
     */
    public $timestamps = false;

    /**
     * Disable auto-incrementing since we use UUID.
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * BR-507 — audit records are append-only. No role may edit or delete them.
     *
     * Enforced here rather than left to a policy, because a policy only guards
     * the HTTP surface. The realistic way an audit row gets rewritten is a
     * service or a console command doing it directly, and this catches that
     * too. An audit trail that can be edited is not evidence of anything.
     *
     * Production should also revoke UPDATE and DELETE on this table from the
     * application's database role; this guard is the second line, not the only
     * one.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at = $model->freshTimestamp();
        });

        static::updating(function () {
            throw new BusinessRuleException(
                'Audit records are append-only and cannot be modified.',
            );
        });

        static::deleting(function () {
            throw new BusinessRuleException(
                'Audit records are append-only and cannot be deleted.',
            );
        });
    }

    // ============================================================================
    // RELATIONSHIPS
    // ============================================================================

    /**
     * Get the user who performed the audited action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================================================
    // SCOPES
    // ============================================================================

}
