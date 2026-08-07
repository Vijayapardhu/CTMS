<?php

namespace App\Enums;

/**
 * Student record status. Must match the `students.status` column definition.
 */
enum StudentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case SUSPENDED = 'SUSPENDED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this student may be given transport and board a bus.
     */
    public function isEligibleForTransport(): bool
    {
        return $this === self::ACTIVE;
    }
}
