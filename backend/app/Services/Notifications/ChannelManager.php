<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannelDriver;
use App\Enums\NotificationChannel;
use App\Notifications\Channels\EmailChannelDriver;
use App\Notifications\Channels\InAppChannelDriver;
use App\Notifications\Channels\PushChannelDriver;
use App\Notifications\Channels\SmsChannelDriver;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Resolves the driver for a channel.
 *
 * Channels are pluggable: swapping the SMS provider is a binding change here
 * and nothing else in the platform moves.
 */
class ChannelManager
{
    /** @var array<string, class-string<NotificationChannelDriver>> */
    private array $drivers = [
        'PUSH' => PushChannelDriver::class,
        'EMAIL' => EmailChannelDriver::class,
        'SMS' => SmsChannelDriver::class,
        'IN_APP' => InAppChannelDriver::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function driver(NotificationChannel $channel): NotificationChannelDriver
    {
        $class = $this->drivers[$channel->value] ?? null;

        if ($class === null) {
            throw new RuntimeException("No driver registered for channel [{$channel->value}].");
        }

        return $this->container->make($class);
    }

    /**
     * Replace a channel's driver. Used by an installation swapping providers,
     * and by tests asserting delivery without a live gateway.
     *
     * @param  class-string<NotificationChannelDriver>  $driver
     */
    public function extend(NotificationChannel $channel, string $driver): void
    {
        $this->drivers[$channel->value] = $driver;
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function enabledChannels(): array
    {
        return array_values(array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel) => $channel->isEnabled(),
        ));
    }
}
