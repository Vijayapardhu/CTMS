<?php

namespace App\Enums;

/**
 * What a notification is about (BR-403, BR-404).
 *
 * Category governs *preferences*: a user chooses which categories reach them
 * and on which channels. It does not govern urgency — that is
 * {@see NotificationPriority}, set per event, because "trip started" and
 * "trip cancelled" share a category and could not differ more in urgency.
 */
enum NotificationCategory: string
{
    /** Trip lifecycle: started, completed, cancelled, delayed. */
    case TRIP = 'TRIP';

    /** Bus approaching or arrived at a stop. */
    case ARRIVAL = 'ARRIVAL';

    /** Boarding and alighting confirmations. */
    case ATTENDANCE = 'ATTENDANCE';

    /** Incidents, SOS, passengers left behind, skipped stops. */
    case INCIDENT = 'INCIDENT';

    /** Route, stop and timetable assignment changes. */
    case TRANSPORT = 'TRANSPORT';

    /** Fleet operations: duty, vehicle status, document and licence expiry. */
    case FLEET = 'FLEET';

    /** Security and account state. */
    case ACCOUNT = 'ACCOUNT';

    /** Passes, payments and dues. */
    case FINANCE = 'FINANCE';

    /** Broadcast messages from the transport office. */
    case ANNOUNCEMENT = 'ANNOUNCEMENT';

    /** Operational alerts for staff. */
    case SYSTEM = 'SYSTEM';

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
            self::TRIP => 'Trip updates',
            self::ARRIVAL => 'Bus arriving',
            self::ATTENDANCE => 'Boarding confirmations',
            self::INCIDENT => 'Safety and incidents',
            self::TRANSPORT => 'Transport assignment',
            self::FLEET => 'Fleet and duty',
            self::ACCOUNT => 'Account and security',
            self::FINANCE => 'Passes and payments',
            self::ANNOUNCEMENT => 'Announcements',
            self::SYSTEM => 'System alerts',
        };
    }

    /**
     * BR-404 — whether a user may switch this category off.
     *
     * Safety and security categories are locked on and are displayed as such
     * with an explanation. Hiding the fact that they cannot be muted breeds
     * distrust; refusing to send them gets someone hurt.
     */
    public function isMutable(): bool
    {
        return match ($this) {
            self::INCIDENT, self::ACCOUNT => false,
            default => true,
        };
    }

    /**
     * Channels a user receives this category on unless they say otherwise.
     *
     * @return array<int, NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::INCIDENT => [
                NotificationChannel::PUSH,
                NotificationChannel::SMS,
                NotificationChannel::IN_APP,
            ],
            self::ACCOUNT => [
                NotificationChannel::EMAIL,
                NotificationChannel::IN_APP,
            ],
            self::FINANCE => [
                NotificationChannel::EMAIL,
                NotificationChannel::IN_APP,
            ],
            self::ATTENDANCE, self::ARRIVAL => [
                NotificationChannel::PUSH,
                NotificationChannel::IN_APP,
            ],
            default => [
                NotificationChannel::PUSH,
                NotificationChannel::IN_APP,
            ],
        };
    }
}
