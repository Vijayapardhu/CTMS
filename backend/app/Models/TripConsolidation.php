<?php

namespace App\Models;

use App\Enums\ConsolidationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A proposal to stand one trip down and move its passengers onto another
 * (FR-13).
 *
 * `$fillable` is empty on purpose. Every field here is either evidence
 * captured at proposal time or the record of a decision, and neither may be
 * set from a request payload.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property ConsolidationStatus $status
 */
class TripConsolidation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

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
            'status' => ConsolidationStatus::class,
            'source_passengers' => 'integer',
            'target_passengers' => 'integer',
            'target_capacity' => 'integer',
            'divergence_sequence' => 'integer',
            'estimated_savings' => 'decimal:2',
            'decided_at' => 'datetime',
            'passengers_notified_at' => 'datetime',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function sourceTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'source_trip_id');
    }

    public function targetTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'target_trip_id');
    }

    public function divergenceStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'divergence_stop_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ConsolidationStatus::PROPOSED->value,
            ConsolidationStatus::APPROVED->value,
        ]);
    }

    /**
     * Proposals whose window has passed and which nobody decided.
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->open()->where('expires_at', '<=', now());
    }

    // ========================================================================
    // STATE
    // ========================================================================

    /**
     * BR-362 — everybody has to fit on the bus that remains.
     */
    public function combinedPassengers(): int
    {
        return $this->source_passengers + $this->target_passengers;
    }

    /**
     * The single definition of BR-362.
     *
     * Static because the rule has to be asked *before* a proposal row exists,
     * at proposal time, as well as against a saved one at approval and at
     * execution. It used to be written out inline at each of those three
     * points, with this model's method sitting unused beside them — which
     * mutation testing found by loosening the unused copy and watching the
     * whole suite pass.
     */
    public static function fits(int $sourcePassengers, int $targetPassengers, int $targetCapacity): bool
    {
        return $sourcePassengers + $targetPassengers <= $targetCapacity;
    }

    /**
     * BR-363 — execution is gated on the passengers having been told first.
     */
    public function passengersHaveBeenTold(): bool
    {
        return $this->passengers_notified_at !== null;
    }

    public function hasLapsed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
