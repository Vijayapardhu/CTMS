<?php

namespace App\Models;

use App\Enums\RouteStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type. Without these, a comparison against the enum
 * looks to the analyser like a comparison against a string — which is the
 * exact defect class this codebase started with.
 *
 * @property RouteStatus $status
 */
class Route extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * `number_of_stops` is derived from the stops themselves and is never
     * accepted from a request — a client-supplied count would drift from
     * reality the moment a stop is added.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'route_name',
        'route_code',
        'description',
        'total_distance_km',
        'estimated_duration_minutes',
        'start_point',
        'end_point',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RouteStatus::class,
            'total_distance_km' => 'float',
            'estimated_duration_minutes' => 'integer',
            'number_of_stops' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    /**
     * Stops in running order. Always ordered — a route whose stops come back
     * in insertion order is a route with the wrong itinerary.
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('sequence_number');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', RouteStatus::ACTIVE->value);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    public function isServiceable(): bool
    {
        return $this->status->isServiceable();
    }

    /**
     * Recount the stops and persist the total.
     */
    public function syncStopCount(): void
    {
        $this->forceFill(['number_of_stops' => $this->stops()->count()])->save();
    }

    /**
     * Seats available to assign on this route (BR-159).
     *
     * Every assigned student must fit on *every* scheduled run — a student
     * assigned to the route rides both the morning and the evening service —
     * so the binding constraint is the smallest bus scheduled on it, not the
     * sum of them. The safety margin is then held back for unplanned riders.
     *
     * Returns null when no active schedule exists: at term start students are
     * assigned before the timetable is built, and a route with no buses yet
     * has no capacity to compare against rather than a capacity of zero.
     */
    public function assignableCapacity(): ?int
    {
        $smallestBus = $this->schedules()
            ->where('is_active', true)
            ->join('buses', 'buses.id', '=', 'schedules.bus_id')
            ->whereNull('buses.deleted_at')
            ->min('buses.seating_capacity');

        if ($smallestBus === null) {
            return null;
        }

        $margin = (int) config('ctms.capacity.safety_margin_seats', 0);

        return max(0, (int) $smallestBus - $margin);
    }

    /**
     * Students currently assigned to this route.
     */
    public function assignedStudentCount(): int
    {
        return $this->students()->count();
    }
}
