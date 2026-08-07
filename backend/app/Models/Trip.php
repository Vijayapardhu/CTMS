<?php

namespace App\Models;

use App\Enums\TripStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One bus, one route, one day (FR-06).
 *
 * `status` and every attribution column are absent from `$fillable`: the
 * lifecycle is owned by TripService, which enforces the state machine, the
 * start gate and the audit trail. A trip whose status could be assigned
 * directly would let an unroadworthy bus onto the road.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property TripStatus $status
 */
class Trip extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'schedule_id',
        'bus_id',
        'driver_id',
        'route_id',
        'trip_date',
        'scheduled_departure_time',
        'scheduled_arrival_time',
        'booked_seat_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'trip_date' => 'date',
            'booked_seat_count' => 'integer',
            'occupied_seat_count' => 'integer',
            'current_latitude' => 'float',
            'current_longitude' => 'float',
            'last_gps_update' => 'datetime',
            'cancelled_at' => 'datetime',
            'generated_at' => 'datetime',
            'auto_closed' => 'boolean',
            'average_speed_kmh' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class);
    }

    public function passengerLogs(): HasMany
    {
        return $this->hasMany(PassengerLog::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function stopProgress(): HasMany
    {
        return $this->hasMany(TripStopProgress::class);
    }

    /**
     * Where this trip's passengers went when it was stood down by a merge.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'merged_into_trip_id');
    }

    /**
     * BR-258 — who the trip started with, when a driver was swapped mid-route.
     */
    public function originalDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'original_driver_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(TripCorrection::class);
    }

    public function discrepancy(): HasOne
    {
        return $this->hasOne(AttendanceDiscrepancy::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeStatus(Builder $query, TripStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Trips that have not reached a terminal state.
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TripStatus::SCHEDULED->value,
            TripStatus::RUNNING->value,
        ]);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isScheduled(): bool
    {
        return $this->status === TripStatus::SCHEDULED;
    }

    public function isRunning(): bool
    {
        return $this->status === TripStatus::RUNNING;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * When this trip is due to depart, as a full timestamp.
     */
    public function scheduledDepartureAt(): CarbonInterface
    {
        return $this->trip_date->copy()->setTimeFromTimeString($this->scheduled_departure_time);
    }

    public function scheduledArrivalAt(): CarbonInterface
    {
        return $this->trip_date->copy()->setTimeFromTimeString($this->scheduled_arrival_time);
    }

    /**
     * BR-252 — a trip cannot start more than the configured window before its
     * scheduled departure. Prevents a bus leaving an hour early with nobody
     * aboard.
     */
    public function isWithinStartWindow(): bool
    {
        $window = (int) config('ctms.trip.checkin_window_minutes', 15);

        return now()->gte($this->scheduledDepartureAt()->copy()->subMinutes($window));
    }

    /**
     * Whether this trip has run past the point where it should have closed
     * itself (BR-260).
     */
    public function isOverdueForClosure(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        $buffer = (int) config('ctms.trip.completion_buffer_minutes', 30);

        return now()->gt($this->scheduledArrivalAt()->copy()->addMinutes($buffer));
    }

    /**
     * Minutes late against the schedule. Negative means early.
     */
    public function delayMinutes(): int
    {
        if ($this->actual_departure_time === null) {
            return 0;
        }

        $actual = $this->trip_date->copy()->setTimeFromTimeString($this->actual_departure_time);

        return (int) $this->scheduledDepartureAt()->diffInMinutes($actual, false);
    }
}
