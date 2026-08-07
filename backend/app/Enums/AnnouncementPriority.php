<?php

namespace App\Enums;

/**
 * How loudly an announcement is delivered.
 */
enum AnnouncementPriority: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * How this maps onto the notification platform's two priorities.
     *
     * Deliberately never CRITICAL. Critical bypasses quiet hours, mute and
     * preference (BR-402), and that exemption belongs to a child in danger —
     * not to whoever is writing the notice board. An announcement that truly
     * cannot wait is an incident, and there is a route for that.
     */
    public function notificationPriority(): NotificationPriority
    {
        return NotificationPriority::STANDARD;
    }

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
        };
    }
}
