<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recurring service due on a bus (BG-16, BR-366).
 *
 * Due-ness is whichever comes first, time or distance. A bus covering 400km a
 * day and one covering 40 do not need the same interval, and either rule on
 * its own lets a vehicle run far past a service it needed.
 */
class PreventiveMaintenanceSchedule extends Model
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
            'interval_days' => 'integer',
            'interval_km' => 'integer',
            'last_serviced_on' => 'date',
            'last_serviced_odometer' => 'integer',
            'due_on' => 'date',
            'due_at_odometer' => 'integer',
            'grace_days' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function openTicket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class, 'open_ticket_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ========================================================================
    // DUE-NESS
    // ========================================================================

    /**
     * Whether the service is due, on either axis.
     */
    public function isDue(?int $currentOdometer = null): bool
    {
        return $this->isDueByDate() || $this->isDueByDistance($currentOdometer);
    }

    public function isDueByDate(): bool
    {
        return $this->due_on !== null && $this->due_on->isSameDay(today())
            || $this->due_on !== null && $this->due_on->isPast();
    }

    public function isDueByDistance(?int $currentOdometer = null): bool
    {
        $odometer = $currentOdometer ?? $this->bus?->current_odometer;

        return $this->due_at_odometer !== null
            && $odometer !== null
            && $odometer >= $this->due_at_odometer;
    }

    /**
     * BR-366 — the point past which the bus may no longer be assigned.
     *
     * Note that grace applies to the *date* axis only. A distance overrun has
     * no equivalent slack: a bus that has done the kilometres has done them.
     */
    public function isPastGracePeriod(?int $currentOdometer = null): bool
    {
        if ($this->isDueByDistance($currentOdometer)) {
            return true;
        }

        if ($this->due_on === null) {
            return false;
        }

        return $this->due_on->copy()->addDays($this->grace_days)->isPast();
    }
}
