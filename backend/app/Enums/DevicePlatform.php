<?php

namespace App\Enums;

/**
 * A registered device that can receive push notifications.
 */
enum DevicePlatform: string
{
    case IOS = 'IOS';
    case ANDROID = 'ANDROID';
    case WEB = 'WEB';

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
            self::IOS => 'iOS',
            self::ANDROID => 'Android',
            self::WEB => 'Web browser',
        };
    }
}
