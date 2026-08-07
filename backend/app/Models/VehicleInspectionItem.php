<?php

namespace App\Models;

use App\Enums\InspectionItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checklist verdict within an inspection.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property InspectionItem $item
 */
class VehicleInspectionItem extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'vehicle_inspection_id',
        'item',
        'passed',
        'notes',
        'photo_path',
    ];

    /**
     * BR-367 — the stored evidence path never leaves the API.
     *
     * `VehicleIncident` hid this from the start and this model did not, so the
     * same rule was enforced on one half of the evidence and not the other.
     * A path in a response is a URL somebody eventually fetches without a
     * check.
     *
     * @var array<int, string>
     */
    protected $hidden = ['photo_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item' => InspectionItem::class,
            'passed' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'vehicle_inspection_id');
    }

    /**
     * Whether this failure is one that stops the bus.
     */
    public function isBlockingFailure(): bool
    {
        return ! $this->passed && $this->item->isSafetyCritical();
    }
}
