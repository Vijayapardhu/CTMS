<?php

namespace App\Enums;

/**
 * User role enumeration.
 *
 * Defines the three principal actor types in CTMS. Values are UPPERCASE and must
 * match the `users.role` column definition exactly (see create_users_table).
 */
enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case DRIVER = 'DRIVER';
    case STUDENT = 'STUDENT';

    /**
     * Human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::DRIVER => 'Driver',
            self::STUDENT => 'Student',
        };
    }

    /**
     * All values as plain strings, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
