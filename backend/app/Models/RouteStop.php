<?php

namespace App\Models;

use App\Enums\StopType;
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
 * @property StopType $stop_type
 */
class RouteStop extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * `sequence_number` is managed by RouteService so the running order stays
     * gap-free and unambiguous.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'route_id',
        'stop_name',
        'latitude',
        'longitude',
        'address',
        'landmark',
        'distance_from_start_km',
        'estimated_arrival_minutes',
        'waiting_time_minutes',
        'stop_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stop_type' => StopType::class,
            'sequence_number' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_from_start_km' => 'integer',
            'estimated_arrival_minutes' => 'integer',
            'waiting_time_minutes' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * Students who board here.
     */
    public function pickupStudents(): HasMany
    {
        return $this->hasMany(Student::class, 'pickup_stop_id');
    }

    /**
     * Students who alight here.
     */
    public function dropoffStudents(): HasMany
    {
        return $this->hasMany(Student::class, 'dropoff_stop_id');
    }

    // ========================================================================
    // GEO
    // ========================================================================

    /**
     * Great-circle distance in metres from this stop to a position.
     *
     * Used for geofencing: deciding whether a bus has arrived at this stop.
     */
    public function distanceInMetresTo(float $latitude, float $longitude): float
    {
        $earthRadius = 6_371_000;

        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($latitude);
        $latDelta = $latTo - $latFrom;
        $lonDelta = deg2rad($longitude) - deg2rad($this->longitude);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Whether a position falls inside this stop's geofence.
     */
    public function isWithinGeofence(float $latitude, float $longitude, ?int $radiusMetres = null): bool
    {
        $radius = $radiusMetres ?? (int) config('ctms.gps.geofence_radius_meters', 100);

        return $this->distanceInMetresTo($latitude, $longitude) <= $radius;
    }
}
