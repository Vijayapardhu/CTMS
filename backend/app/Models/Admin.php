<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admin extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /**
     * `access_level` and the `can_*` flags are absent on purpose.
     *
     * They are privilege, and privilege is never taken from a payload
     * (CLAUDE.md §2). Today the registration FormRequest is what stops a
     * self-service caller reaching them, which means the guard lives in one
     * place and a future endpoint that forgets it would hand out
     * `SUPER_ADMIN`. AuthService sets them explicitly after its own check.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'designation',
        'department',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'access_level' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Disable auto-incrementing since we use UUID.
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    // ============================================================================
    // RELATIONSHIPS
    // ============================================================================

    /**
     * Get the user associated with this admin.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all announcements created by this admin.
     */
    public function createdAnnouncements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }
}
