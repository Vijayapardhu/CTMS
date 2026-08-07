<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only follow-up on an incident (BR-357).
 *
 * The original report is never edited. Everything learned afterwards is added
 * here, attributed and timestamped, so the sequence of what was known when can
 * be reconstructed.
 */
class IncidentNote extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = ['note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function incident(): BelongsTo
    {
        return $this->belongsTo(VehicleIncident::class, 'vehicle_incident_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
