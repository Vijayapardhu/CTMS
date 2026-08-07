<?php

namespace App\Models;

use App\Enums\EvidenceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stored file held as evidence (BR-367).
 *
 * `$hidden` carries `disk` and `path`. Those are the two fields that would
 * turn an id into a URL somebody eventually fetches without a check, and the
 * whole point of this table is that they never leave the server.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type.
 *
 * @property EvidenceCategory $category
 */
class EvidenceFile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Everything here is decided by EvidenceService from the uploaded file
     * itself. A payload that could set `path` could read any file on the disk.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    /**
     * @var array<int, string>
     */
    protected $hidden = ['disk', 'path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => EvidenceCategory::class,
            'size_bytes' => 'integer',
            'attached_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * The incident, inspection item, ticket or document this belongs to.
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Uploaded but never attached to anything.
     *
     * A driver who starts a report and abandons it leaves a file behind. These
     * are what the retention sweep collects; an attached one is evidence and
     * is never touched by it.
     */
    public function scopeOrphaned(Builder $query): Builder
    {
        return $query->whereNull('attachable_id');
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isAttached(): bool
    {
        return $this->attachable_id !== null;
    }

    /**
     * A filename safe to put in a Content-Disposition header.
     *
     * The stored name came from a client, so it is rebuilt from the id and the
     * extension rather than echoed back.
     */
    public function downloadName(): string
    {
        $extension = pathinfo($this->original_name, PATHINFO_EXTENSION);
        $extension = preg_replace('/[^a-z0-9]/i', '', (string) $extension);

        return $extension === ''
            ? (string) $this->getKey()
            : "{$this->getKey()}.{$extension}";
    }
}
