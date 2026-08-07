<?php

namespace App\Models;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleFrequency;
use Carbon\CarbonInterface;
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
 * @property DayOfWeek $day_of_week
 * @property ScheduleFrequency $frequency
 */
class Schedule extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'route_id',
        'bus_id',
        'driver_id',
        'departure_time',
        'arrival_time',
        'day_of_week',
        'frequency',
        'start_date',
        'end_date',
        'expected_passenger_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => DayOfWeek::class,
            'frequency' => ScheduleFrequency::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'expected_passenger_count' => 'integer',
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

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    /**
     * Whether this schedule should produce a trip on the given date.
     *
     * All three must agree: the schedule is switched on, the date falls inside
     * its validity window, and both the weekday and the frequency match.
     */
    public function runsOn(CarbonInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->start_date && $date->lt($this->start_date->startOfDay())) {
            return false;
        }

        if ($this->end_date && $date->gt($this->end_date->endOfDay())) {
            return false;
        }

        $day = DayOfWeek::fromDate($date);

        return $this->day_of_week === $day && $this->frequency->coversDay($day);
    }

    /**
     * Whether this schedule runs today.
     */
    public function isActiveToday(): bool
    {
        return $this->runsOn(now());
    }

    /**
     * Whether this schedule's time window overlaps another's.
     *
     * Touching endpoints do not overlap: a bus arriving at 09:00 is free to
     * depart again at 09:00.
     */
    public function overlapsTimeWindowOf(self $other): bool
    {
        return $this->departure_time < $other->arrival_time
            && $other->departure_time < $this->arrival_time;
    }
}
