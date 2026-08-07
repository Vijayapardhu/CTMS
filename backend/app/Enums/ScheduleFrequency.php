<?php

namespace App\Enums;

/**
 * How often a schedule recurs. Must match the `schedules.frequency` column.
 */
enum ScheduleFrequency: string
{
    case DAILY = 'DAILY';
    case WEEKDAYS = 'WEEKDAYS';
    case WEEKENDS = 'WEEKENDS';
    case ONCE = 'ONCE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether a schedule with this frequency runs on the given day.
     */
    public function coversDay(DayOfWeek $day): bool
    {
        return match ($this) {
            self::DAILY, self::ONCE => true,
            self::WEEKDAYS => ! $day->isWeekend(),
            self::WEEKENDS => $day->isWeekend(),
        };
    }
}
