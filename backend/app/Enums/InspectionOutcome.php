<?php

namespace App\Enums;

/**
 * The result of a completed pre-trip inspection (BR-107, BR-108).
 */
enum InspectionOutcome: string
{
    /** Every item passed. The bus may run. */
    case PASSED = 'PASSED';

    /** Only non-critical items failed. The bus may run; a ticket is open. */
    case PASSED_WITH_DEFECTS = 'PASSED_WITH_DEFECTS';

    /** A safety-critical item failed. The bus is blocked. */
    case FAILED = 'FAILED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether an inspection with this outcome permits the trip to start.
     */
    public function clearsForService(): bool
    {
        return $this !== self::FAILED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PASSED => 'Passed',
            self::PASSED_WITH_DEFECTS => 'Passed with defects',
            self::FAILED => 'Failed',
        };
    }
}
