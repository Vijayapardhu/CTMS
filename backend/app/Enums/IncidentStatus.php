<?php

namespace App\Enums;

/**
 * The incident lifecycle.
 *
 *   REPORTED → ACKNOWLEDGED → IN_PROGRESS → RESOLVED → CLOSED
 *        └──────────── ESCALATED ────────────┘
 *
 * Acknowledgement and resolution are separate states on purpose:
 * "someone has seen this" and "this is dealt with" are different facts, and
 * conflating them is how an incident sits unattended while looking handled.
 */
enum IncidentStatus: string
{
    case REPORTED = 'REPORTED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case ESCALATED = 'ESCALATED';
    case RESOLVED = 'RESOLVED';
    case CLOSED = 'CLOSED';

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
            self::REPORTED => [self::ACKNOWLEDGED, self::IN_PROGRESS, self::ESCALATED, self::RESOLVED],
            self::ACKNOWLEDGED => [self::IN_PROGRESS, self::ESCALATED, self::RESOLVED],
            self::IN_PROGRESS => [self::ESCALATED, self::RESOLVED],
            self::ESCALATED => [self::IN_PROGRESS, self::RESOLVED],
            self::RESOLVED => [self::CLOSED, self::IN_PROGRESS],
            self::CLOSED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return $this !== self::CLOSED && $this !== self::RESOLVED;
    }

    public function isAcknowledged(): bool
    {
        return $this !== self::REPORTED;
    }
}
