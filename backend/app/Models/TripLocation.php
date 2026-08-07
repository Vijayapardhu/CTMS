<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripLocation extends Model
{
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'trip_locations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'trip_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'speed_kmh',
        'recorded_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'speed_kmh' => 'float',
            'heading' => 'float',
            'altitude_meters' => 'integer',
            'recorded_at' => 'datetime',
            'device_recorded_at' => 'datetime',
            'clock_skew_suspected' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Disable auto-incrementing since we use UUID.
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     * Trip locations use custom recorded_at column for when the location was recorded.
     */
    public $timestamps = true;

    // ============================================================================
    // RELATIONSHIPS
    // ============================================================================

    /**
     * Get the trip this location record belongs to.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    // ============================================================================
    // INDEXES
    // ============================================================================

    /**
     * Get the indexable data array for this model.
     * Trip locations should be indexed by (trip_id, recorded_at) for time-series queries.
     * This is typically defined in migrations.
     */
}
