<?php

namespace App\Enums;

/**
 * Who a service announcement is for.
 *
 * UPPERCASE and canonical, matching the `target_audience` column. The
 * placeholder model compared this against lowercase literals, which would have
 * matched nothing.
 */
enum AnnouncementAudience: string
{
    case ALL = 'ALL';
    case STUDENTS = 'STUDENTS';
    case DRIVERS = 'DRIVERS';
    case ADMINS = 'ADMINS';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The roles this audience resolves to.
     *
     * @return array<int, UserRole>
     */
    public function roles(): array
    {
        return match ($this) {
            self::ALL => [UserRole::ADMIN, UserRole::DRIVER, UserRole::STUDENT],
            self::STUDENTS => [UserRole::STUDENT],
            self::DRIVERS => [UserRole::DRIVER],
            self::ADMINS => [UserRole::ADMIN],
        };
    }

    /**
     * Whether somebody holding this role should see the announcement.
     */
    public function includes(UserRole $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Everyone',
            self::STUDENTS => 'Students',
            self::DRIVERS => 'Drivers',
            self::ADMINS => 'Administrators',
        };
    }
}
