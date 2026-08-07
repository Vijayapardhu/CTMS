<?php

namespace App\Models;

use App\Enums\BusStatus;
use App\Enums\DocumentType;
use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type. Without these, a comparison against the enum
 * looks to the analyser like a comparison against a string — which is the
 * exact defect class this codebase started with.
 *
 * @property BusStatus $status
 */
class Bus extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'buses';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'registration_number',
        'vehicle_name',
        'model',
        'year_of_manufacture',
        'seating_capacity',
        'fuel_type',
        'mileage',
        'last_maintenance_date',
        'next_maintenance_due',
        'color',
        'gps_device_id',
        'remarks',
    ];

    /**
     * `status` is deliberately not fillable: it only ever changes through
     * {@see BusService::changeStatus()}, which enforces the state machine and
     * writes an audit record.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BusStatus::class,
            'mileage' => 'float',
            'current_odometer' => 'integer',
            'seating_capacity' => 'integer',
            'year_of_manufacture' => 'integer',
            'last_maintenance_date' => 'datetime',
            'next_maintenance_due' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function tripLocations(): HasManyThrough
    {
        return $this->hasManyThrough(TripLocation::class, Trip::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(VehicleIncident::class);
    }

    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusDocument::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeStatus(Builder $query, BusStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Buses that can be dispatched right now.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', BusStatus::AVAILABLE->value);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isAvailable(): bool
    {
        return $this->status === BusStatus::AVAILABLE;
    }

    /**
     * BR-061 — odometer readings are monotonic.
     *
     * Every source of a reading (pre-trip inspection, workshop, trip close)
     * goes through here, so there is one definition of "backwards" rather than
     * one per caller. A reading below the recorded total is either a typo or a
     * tampered instrument; both need a human, and neither may be written.
     *
     * @throws BusinessRuleException
     */
    public function recordOdometer(int $reading): void
    {
        $current = $this->current_odometer;

        if ($current !== null && $reading < $current) {
            throw new BusinessRuleException(
                "The odometer cannot go backwards: this bus is recorded at {$current} km "
                ."and the reading submitted is {$reading} km.",
                ['recorded' => $current, 'submitted' => $reading],
            );
        }

        if ($current === $reading) {
            return;
        }

        $this->forceFill(['current_odometer' => $reading])->save();
    }

    /**
     * Whether this bus is currently tied to a trip that has not finished.
     * Such a bus must not be deleted or taken off the road silently.
     */
    public function hasActiveTrip(): bool
    {
        return $this->trips()
            ->whereIn('status', [TripStatus::SCHEDULED->value, TripStatus::RUNNING->value])
            ->exists();
    }

    // ========================================================================
    // COMPLIANCE — BR-055
    // ========================================================================

    /**
     * Mandatory documents that are missing or lapsed.
     *
     * A missing document counts as a failure exactly like an expired one: an
     * unrecorded insurance certificate is indistinguishable from no insurance.
     *
     * @return array<int, DocumentType>
     */
    public function missingOrExpiredDocuments(): array
    {
        $current = $this->documents()
            ->whereNull('superseded_by_id')
            ->get()
            ->keyBy(fn (BusDocument $document) => $document->document_type->value);

        $failures = [];

        foreach (DocumentType::mandatory() as $type) {
            $document = $current->get($type->value);

            if ($document === null || $document->isExpired()) {
                $failures[] = $type;
            }
        }

        return $failures;
    }

    /**
     * Whether every mandatory document is present and in force.
     */
    public function hasValidDocuments(): bool
    {
        return $this->missingOrExpiredDocuments() === [];
    }

    // ========================================================================
    // INSPECTION — BR-107
    // ========================================================================

    /**
     * The most recent inspection for a given day, if one was submitted.
     */
    public function inspectionOn(?CarbonInterface $date = null): ?VehicleInspection
    {
        return $this->inspections()
            ->whereDate('inspected_on', ($date ?? now())->toDateString())
            ->latest('inspected_at')
            // Two inspections in the same second — a re-inspection straight
            // after a repair — must resolve deterministically to the later
            // one. Ordered UUIDs sort by creation time, so the key breaks the
            // tie that the timestamp alone cannot.
            ->orderByDesc('id')
            ->first();
    }
}
