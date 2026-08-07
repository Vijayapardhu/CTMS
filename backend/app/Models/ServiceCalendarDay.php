<?php

namespace App\Models;

use App\Enums\ServiceDayType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day the service does not run normally (BR-264).
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property ServiceDayType $day_type
 */
class ServiceCalendarDay extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'date',
        'day_type',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'day_type' => ServiceDayType::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by_id');
    }

    /**
     * Whether the service is suspended on the given date, and why.
     */
    public static function suspensionOn(CarbonInterface $date): ?self
    {
        $day = static::whereDate('date', $date->toDateString())->first();

        return $day?->day_type->suspendsService() ? $day : null;
    }

    /**
     * Whether trips should be generated for this date.
     */
    public static function isOperating(CarbonInterface $date): bool
    {
        return static::suspensionOn($date) === null;
    }
}
