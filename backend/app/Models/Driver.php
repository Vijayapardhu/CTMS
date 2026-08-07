<?php

namespace App\Models;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type. Without these, a comparison against the enum
 * looks to the analyser like a comparison against a string — which is the
 * exact defect class this codebase started with.
 *
 * @property DriverStatus $status
 */
class Driver extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * `status` and `assigned_bus_id` are not mass assignable: both are changed
     * only through DriverService, which enforces the state machine, the licence
     * check and the audit trail.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'license_number',
        'license_class',
        'license_expiry_date',
        'vehicle_registration',
        'current_latitude',
        'current_longitude',
        'last_gps_update',
        'violations_history',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'license_expiry_date' => 'date',
            'current_latitude' => 'float',
            'current_longitude' => 'float',
            'last_gps_update' => 'datetime',
            'total_trips' => 'integer',
            'average_rating' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The bus this driver is currently responsible for, if any.
     */
    public function assignedBus(): BelongsTo
    {
        return $this->belongsTo(Bus::class, 'assigned_bus_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class, 'assigned_to');
    }

    public function vehicleIncidents(): HasMany
    {
        return $this->hasMany(VehicleIncident::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeStatus(Builder $query, DriverStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Drivers who may be given a trip right now: on duty, free, and holding a
     * licence that has not lapsed.
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('status', DriverStatus::AVAILABLE->value)
            ->whereDate('license_expiry_date', '>', now());
    }

    // ========================================================================
    // STATE
    // ========================================================================

    /**
     * A licence that expires today is already unusable for tomorrow's trip;
     * only a strictly future date counts as valid.
     */
    public function isLicenseValid(): bool
    {
        return $this->license_expiry_date !== null
            && $this->license_expiry_date->isAfter(now()->startOfDay());
    }

    /**
     * Whether this driver may be handed a new trip.
     */
    public function isAssignable(): bool
    {
        return $this->status->isAssignable() && $this->isLicenseValid();
    }

    public function hasActiveTrip(): bool
    {
        return $this->trips()
            ->whereIn('status', [TripStatus::SCHEDULED->value, TripStatus::RUNNING->value])
            ->exists();
    }

    public function updateCurrentLocation(float $latitude, float $longitude): void
    {
        $this->forceFill([
            'current_latitude' => $latitude,
            'current_longitude' => $longitude,
            'last_gps_update' => now(),
        ])->save();
    }
}
