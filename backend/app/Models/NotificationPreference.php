<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person has chosen to receive for one category (BR-403).
 *
 * Absence of a row means "the defaults apply" — a user who has never opened
 * their preferences still receives everything they should.
 *
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type — the difference is what makes an
 * enum-to-enum comparison look like a string comparison.
 *
 * @property NotificationCategory $category
 */
class NotificationPreference extends Model
{
    use HasFactory, HasUuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'category',
        'channels',
        'muted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'channels' => 'array',
            'muted' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Channels this preference selects, as enum cases.
     *
     * @return array<int, NotificationChannel>
     */
    public function selectedChannels(): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => NotificationChannel::tryFrom((string) $value),
            $this->channels ?? [],
        )));
    }
}
