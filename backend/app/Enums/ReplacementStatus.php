<?php

namespace App\Enums;

/**
 * The replacement-bus lifecycle (FR-12, BR-359).
 *
 *   RECOMMENDED → APPROVED → DISPATCHED → ARRIVED → COMPLETED
 *         └────── REJECTED
 *
 * Recommendation and approval are separate because dispatching a replacement
 * costs money and moves a vehicle off another duty. The system proposes; a
 * human decides.
 */
enum ReplacementStatus: string
{
    case RECOMMENDED = 'RECOMMENDED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case DISPATCHED = 'DISPATCHED';
    case ARRIVED = 'ARRIVED';
    case COMPLETED = 'COMPLETED';

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
            self::RECOMMENDED => [self::APPROVED, self::REJECTED],
            self::APPROVED => [self::DISPATCHED, self::REJECTED],
            self::DISPATCHED => [self::ARRIVED, self::REJECTED],
            self::ARRIVED => [self::COMPLETED],
            self::REJECTED, self::COMPLETED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::REJECTED || $this === self::COMPLETED;
    }
}
