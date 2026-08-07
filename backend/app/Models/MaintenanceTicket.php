<?php

namespace App\Models;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job on a bus (FR-14).
 *
 * `$fillable` is empty. `status` and `priority` decide whether a vehicle is
 * allowed to carry passengers (BR-358), so neither may be set from a request
 * payload; MaintenanceService owns the state machine.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property MaintenanceStatus $status
 * @property MaintenancePriority $priority
 */
class MaintenanceTicket extends Model
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
            'status' => MaintenanceStatus::class,
            'priority' => MaintenancePriority::class,
            'scheduled_date' => 'datetime',
            'completion_date' => 'datetime',
            'started_at' => 'datetime',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
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

    public function incident(): BelongsTo
    {
        return $this->belongsTo(VehicleIncident::class, 'vehicle_incident_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'vehicle_inspection_id');
    }

    /**
     * The mechanic or operations user the job sits with.
     *
     * The placeholder pointed this at Driver through an `assigned_to` column
     * that does not exist; the column is `assigned_to_id` and it references
     * users.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Tickets that still stand between a bus and the road.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            MaintenanceStatus::COMPLETED->value,
            MaintenanceStatus::CANCELLED->value,
        ]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', MaintenanceStatus::COMPLETED->value);
    }

    /**
     * Open tickets serious enough to ground the vehicle (BR-358).
     */
    public function scopeGrounding(Builder $query): Builder
    {
        return $query->open()->whereIn('priority', [
            MaintenancePriority::URGENT->value,
            MaintenancePriority::HIGH->value,
        ]);
    }

    /**
     * Urgent first. A bus with failed brakes must never sit below a bus with a
     * torn seat in the workshop's list.
     */
    public function scopeByUrgency(Builder $query): Builder
    {
        return $query->orderByRaw(
            "CASE priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END"
        )->orderBy('created_at');
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * BR-358 — whether this ticket alone keeps the bus off the road.
     */
    public function groundsTheVehicle(): bool
    {
        return $this->isOpen() && $this->priority->groundsTheVehicle();
    }
}
