<?php

namespace App\Models;

use App\Enums\IncidentClass;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reported incident (FR-11).
 *
 * BR-357 — immutable once submitted. Nothing is fillable: the report is
 * evidence, and evidence that can be edited is worth nothing. Follow-up is
 * appended as {@see IncidentNote} rows.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property IncidentClass $incident_class
 * @property IncidentType $incident_type
 * @property IncidentSeverity $severity
 * @property IncidentStatus $status
 */
class VehicleIncident extends Model
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
            'incident_class' => IncidentClass::class,
            'incident_type' => IncidentType::class,
            'severity' => IncidentSeverity::class,
            'status' => IncidentStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'passengers_aboard' => 'integer',
            'vehicle_can_continue' => 'boolean',
            'was_cancelled' => 'boolean',
            'reported_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'escalated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The photograph is a delivery path, not public data (BR-367).
     *
     * @var array<int, string>
     */
    // Nothing to hide here any more: evidence lives in its own table and
    // is reached through an authorising endpoint (BR-367).
    protected $hidden = [];

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(IncidentNote::class)->latest('created_at');
    }

    public function maintenanceTicket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class);
    }

    public function replacement(): HasMany
    {
        return $this->hasMany(ReplacementAssignment::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IncidentStatus::REPORTED->value,
            IncidentStatus::ACKNOWLEDGED->value,
            IncidentStatus::IN_PROGRESS->value,
            IncidentStatus::ESCALATED->value,
        ]);
    }

    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::REPORTED->value);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isLifeSafety(): bool
    {
        return $this->incident_class === IncidentClass::LIFE_SAFETY;
    }

    public function isAcknowledged(): bool
    {
        return $this->status->isAcknowledged();
    }

    /**
     * Whether this incident has gone unacknowledged past its class's tolerance
     * and must be escalated (BR-356).
     */
    public function isOverdueForEscalation(): bool
    {
        if ($this->isAcknowledged() || $this->status === IncidentStatus::ESCALATED) {
            return false;
        }

        $minutes = $this->incident_class->escalationMinutes();

        if ($minutes === null) {
            return false;
        }

        return $this->reported_at->addMinutes($minutes)->isPast();
    }
}
