<?php

namespace App\Enums;

/**
 * The life of a maintenance ticket (FR-14).
 *
 * UPPERCASE and canonical, like every other enum here. The placeholder model
 * compared these to lowercase literals, which meant `scopeOpen()` matched
 * nothing and every ticket looked open forever.
 */
enum MaintenanceStatus: string
{
    case OPEN = 'OPEN';
    case SCHEDULED = 'SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::SCHEDULED, self::IN_PROGRESS, self::CANCELLED],
            self::SCHEDULED => [self::IN_PROGRESS, self::OPEN, self::CANCELLED],
            // Work already under way cannot be cancelled as though it never
            // happened; it is completed, with whatever was found recorded.
            self::IN_PROGRESS => [self::COMPLETED],
            self::COMPLETED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::CANCELLED;
    }

    /**
     * Whether this ticket still stands between the bus and the road.
     */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::SCHEDULED => 'Scheduled',
            self::IN_PROGRESS => 'In progress',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }
}
