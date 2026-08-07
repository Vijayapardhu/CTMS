<?php

namespace App\Models;

use App\Enums\StudentStatus;
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
 * @property StudentStatus $status
 */
class Student extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Transport assignment columns are absent by design: a student must not be
     * able to seat themselves on a route by posting `route_id`. Assignment
     * goes through StudentService.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'registration_number',
        'department',
        'year_of_study',
        'hostel_name',
        'hostel_room',
        'emergency_contact',
        'emergency_contact_phone',
        'has_valid_ticket',
        'ticket_expiry_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StudentStatus::class,
            'ticket_expiry_date' => 'datetime',
            'transport_assigned_at' => 'datetime',
            'has_valid_ticket' => 'boolean',
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

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function pickupStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'pickup_stop_id');
    }

    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'dropoff_stop_id');
    }

    public function passengerLogs(): HasMany
    {
        return $this->hasMany(PassengerLog::class);
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentStatus::ACTIVE->value);
    }

    // ========================================================================
    // STATE
    // ========================================================================

    /**
     * Whether the student holds a ticket that has not lapsed.
     */
    public function hasActiveTicket(): bool
    {
        if (! $this->has_valid_ticket) {
            return false;
        }

        // A null expiry means an open-ended ticket, not an expired one.
        return $this->ticket_expiry_date === null
            || $this->ticket_expiry_date->isFuture();
    }

    /**
     * Whether the student may board a bus: active record plus a live ticket.
     */
    public function canBoard(): bool
    {
        return $this->status->isEligibleForTransport() && $this->hasActiveTicket();
    }

    public function hasTransportAssigned(): bool
    {
        return $this->route_id !== null && $this->pickup_stop_id !== null;
    }
}
