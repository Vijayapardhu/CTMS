<?php

namespace App\Models;

use App\Enums\ReplacementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed or dispatched replacement vehicle (FR-12).
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property ReplacementStatus $status
 */
class ReplacementAssignment extends Model
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
            'status' => ReplacementStatus::class,
            'distance_metres' => 'integer',
            'passengers_to_transfer' => 'integer',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'arrived_at' => 'datetime',
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

    public function incident(): BelongsTo
    {
        return $this->belongsTo(VehicleIncident::class, 'vehicle_incident_id');
    }

    public function originalBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'original_bus_id');
    }

    public function replacementBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'replacement_bus_id');
    }

    public function replacementDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'replacement_driver_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReplacementStatus::RECOMMENDED->value,
            ReplacementStatus::APPROVED->value,
            ReplacementStatus::DISPATCHED->value,
        ]);
    }
}
