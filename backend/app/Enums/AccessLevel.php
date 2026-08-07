<?php

namespace App\Enums;

/**
 * Administrator access level.
 *
 * Must match the `admins.access_level` column definition exactly. This is a
 * second axis of authorization on top of {@see UserRole}: every holder is an
 * ADMIN, but only some may perform destructive or fleet-wide operations.
 */
enum AccessLevel: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case OPERATIONS = 'OPERATIONS';
    case SUPPORT = 'SUPPORT';
    case VIEWER = 'VIEWER';

    /**
     * All values as plain strings, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Relative privilege, used for "at least this level" comparisons.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SUPER_ADMIN => 40,
            self::OPERATIONS => 30,
            self::SUPPORT => 20,
            self::VIEWER => 10,
        };
    }

    /**
     * Whether this level meets or exceeds the required level.
     */
    public function atLeast(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }
}
