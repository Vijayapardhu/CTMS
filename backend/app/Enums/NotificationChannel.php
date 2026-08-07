<?php

namespace App\Enums;

/**
 * A delivery route to a person.
 *
 * Each channel is independently enabled in configuration, so an institution
 * without an SMS contract simply switches it off and the platform routes
 * around it rather than accumulating failed deliveries.
 */
enum NotificationChannel: string
{
    case PUSH = 'PUSH';
    case EMAIL = 'EMAIL';
    case SMS = 'SMS';
    case IN_APP = 'IN_APP';

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
            self::PUSH => 'Push notification',
            self::EMAIL => 'Email',
            self::SMS => 'SMS',
            self::IN_APP => 'In-app',
        };
    }

    /**
     * Whether this channel is switched on for the installation.
     */
    public function isEnabled(): bool
    {
        return (bool) config("ctms.notifications.channels.{$this->value}.enabled", false);
    }

    /**
     * In-app delivery is always attempted, whatever the user's preferences.
     *
     * The notification centre (SH-15) is the record of what the system told
     * someone. Suppressing the record because push was muted would leave a
     * user unable to find a message they were told about by other means.
     */
    public function isAlwaysDelivered(): bool
    {
        return $this === self::IN_APP;
    }

    /**
     * The channel a failed critical delivery escalates to (BR-406).
     */
    public function escalationTarget(): ?self
    {
        return match ($this) {
            self::PUSH => self::SMS,
            self::SMS => self::EMAIL,
            self::EMAIL, self::IN_APP => null,
        };
    }
}
