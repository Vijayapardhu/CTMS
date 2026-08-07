<?php

namespace App\Enums;

/**
 * The life of a consolidation proposal (FR-13, BR-361..BR-364).
 *
 * The system proposes; a manager decides; execution is a third, separate act
 * that can still be refused if the world moved on in between.
 */
enum ConsolidationStatus: string
{
    case PROPOSED = 'PROPOSED';
    case APPROVED = 'APPROVED';
    case EXECUTED = 'EXECUTED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';

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
            self::PROPOSED => [self::APPROVED, self::REJECTED, self::EXPIRED],
            // An approved-but-unexecuted proposal can still be rejected: the
            // occupancy it was justified by may have changed while it sat.
            self::APPROVED => [self::EXECUTED, self::REJECTED, self::EXPIRED],
            self::EXECUTED, self::REJECTED, self::EXPIRED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the proposal is still awaiting or acting on a decision.
     */
    public function isOpen(): bool
    {
        return $this === self::PROPOSED || $this === self::APPROVED;
    }

    public function label(): string
    {
        return match ($this) {
            self::PROPOSED => 'Proposed',
            self::APPROVED => 'Approved',
            self::EXECUTED => 'Executed',
            self::REJECTED => 'Rejected',
            self::EXPIRED => 'Expired',
        };
    }
}
