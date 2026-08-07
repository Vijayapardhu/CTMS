<?php

namespace App\Enums;

/**
 * Why the service does not run on a given day (BR-264).
 */
enum ServiceDayType: string
{
    /** Planned closure: a public holiday or term break. */
    case HOLIDAY = 'HOLIDAY';

    /** Unplanned closure: weather, strike, civil disruption. */
    case SUSPENSION = 'SUSPENSION';

    /** Service runs, but on an altered timetable. */
    case SPECIAL = 'SPECIAL';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::HOLIDAY => 'Holiday',
            self::SUSPENSION => 'Service suspended',
            self::SPECIAL => 'Special timetable',
        };
    }

    /**
     * Whether trips are generated on a day of this type.
     */
    public function suspendsService(): bool
    {
        return $this !== self::SPECIAL;
    }
}
