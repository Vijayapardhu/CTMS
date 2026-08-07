<?php

namespace App\Models;

use App\Enums\InspectionOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A submitted pre-trip inspection (BR-107, BR-108).
 *
 * Immutable once created. Nothing here is fillable: the whole record is
 * constructed by VehicleInspectionService, which decides the outcome from the
 * items rather than accepting it from a client.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property InspectionOutcome $outcome
 */
class VehicleInspection extends Model
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
            'outcome' => InspectionOutcome::class,
            'inspected_on' => 'date',
            'inspected_at' => 'datetime',
            'odometer_reading' => 'integer',
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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VehicleInspectionItem::class);
    }

    public function maintenanceTicket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('inspected_on', $date);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function clearsForService(): bool
    {
        return $this->outcome->clearsForService();
    }

    /**
     * @return Collection<int, VehicleInspectionItem>
     */
    public function failedItems()
    {
        return $this->items->where('passed', false);
    }

    /**
     * @return Collection<int, VehicleInspectionItem>
     */
    public function failedSafetyCriticalItems()
    {
        return $this->failedItems()->filter(fn (VehicleInspectionItem $item) => $item->item->isSafetyCritical());
    }
}
