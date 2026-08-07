<?php

namespace App\Models;

use App\Enums\StopProgressState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a trip has got to, stop by stop (BR-308).
 *
 * Nothing is fillable: the state machine is owned by GeofenceService, which
 * is the only thing entitled to decide a bus has arrived.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property StopProgressState $state
 */
class TripStopProgress extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'trip_stop_progress';

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
            'state' => StopProgressState::class,
            'sequence_number' => 'integer',
            'inside_readings' => 'integer',
            'boarded_count' => 'integer',
            'alighted_count' => 'integer',
            'entered_at' => 'datetime',
            'arrived_at' => 'datetime',
            'departed_at' => 'datetime',
            'eta_at' => 'datetime',
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

    public function stop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'route_stop_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('state', [
            StopProgressState::PENDING->value,
            StopProgressState::APPROACHING->value,
        ]);
    }

    public function permitsBoarding(): bool
    {
        return $this->state->permitsBoarding();
    }
}
