<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Day of week for weekly schedules. Must match the `schedules.day_of_week`
 * column definition.
 */
enum DayOfWeek: string
{
    case MONDAY = 'MONDAY';
    case TUESDAY = 'TUESDAY';
    case WEDNESDAY = 'WEDNESDAY';
    case THURSDAY = 'THURSDAY';
    case FRIDAY = 'FRIDAY';
    case SATURDAY = 'SATURDAY';
    case SUNDAY = 'SUNDAY';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The day a given date falls on.
     */
    public static function fromDate(CarbonInterface $date): self
    {
        return match ($date->dayOfWeekIso) {
            1 => self::MONDAY,
            2 => self::TUESDAY,
            3 => self::WEDNESDAY,
            4 => self::THURSDAY,
            5 => self::FRIDAY,
            6 => self::SATURDAY,
            default => self::SUNDAY,
        };
    }

    public function isWeekend(): bool
    {
        return $this === self::SATURDAY || $this === self::SUNDAY;
    }
}
