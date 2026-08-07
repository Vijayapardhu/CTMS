<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A statutory document held against a vehicle (BR-055).
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property DocumentType $document_type
 */
class BusDocument extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'bus_id',
        'document_type',
        'document_number',
        'issuing_authority',
        'issued_on',
        'expires_on',
        'file_path',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * The renewal that replaced this document, if any.
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Documents still in force — not superseded by a renewal.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_by_id');
    }

    public function scopeOfType(Builder $query, DocumentType $type): Builder
    {
        return $query->where('document_type', $type->value);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereDate('expires_on', '<', now()->toDateString());
    }

    /**
     * Documents lapsing within the given number of days.
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereDate('expires_on', '>=', now()->toDateString())
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString());
    }

    // ========================================================================
    // STATE
    // ========================================================================

    /**
     * A document that expires today is valid today — cover runs to the end of
     * its final day.
     */
    public function isExpired(): bool
    {
        return $this->expires_on->isBefore(now()->startOfDay());
    }

    public function isValid(): bool
    {
        return ! $this->isExpired();
    }

    public function daysUntilExpiry(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    public function isExpiringWithin(int $days): bool
    {
        $remaining = $this->daysUntilExpiry();

        return $remaining >= 0 && $remaining <= $days;
    }
}
