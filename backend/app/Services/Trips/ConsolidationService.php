<?php

namespace App\Services\Trips;

use App\Enums\ConsolidationStatus;
use App\Enums\TripStatus;
use App\Events\Trips\ConsolidationExecuted;
use App\Events\Trips\ConsolidationProposed;
use App\Exceptions\BusinessRuleException;
use App\Models\RouteStop;
use App\Models\Trip;
use App\Models\TripConsolidation;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Smart consolidation (FR-13, BR-361..BR-364).
 *
 * Two half-empty buses on overlapping roads is money burnt, but merging them
 * is an act performed on people who are standing at a stop expecting a
 * particular bus. So the system may *propose*; only a manager may approve; and
 * execution is refused unless the passengers have already been told and the
 * source bus has not yet passed the point where the two routes part company.
 */
class ConsolidationService
{
    /**
     * How close two stops must be to count as the same place, in metres.
     * Stops are defined per route, so "the same stop" is a geographic
     * judgement rather than a shared identifier.
     */
    private const SAME_STOP_METRES = 150;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Propose merging a low-occupancy trip into another running the same day.
     *
     * @throws BusinessRuleException
     */
    public function propose(
        Trip $source,
        Trip $target,
        User $actor,
        ?string $reason = null,
    ): TripConsolidation {
        return DB::transaction(function () use ($source, $target, $actor, $reason) {
            $source = Trip::whereKey($source->getKey())->lockForUpdate()->firstOrFail();
            $target = Trip::whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $this->assertProposable($source, $target);

            $divergence = $this->divergenceStop($source, $target);

            $consolidation = new TripConsolidation;

            $consolidation->forceFill([
                'source_trip_id' => $source->getKey(),
                'target_trip_id' => $target->getKey(),
                'status' => ConsolidationStatus::PROPOSED,
                'reason' => $reason ?? $this->defaultReason($source, $target),
                // Captured now: the decision is reviewable against what was
                // true when it was proposed.
                'source_passengers' => $source->occupied_seat_count,
                'target_passengers' => $target->occupied_seat_count,
                'target_capacity' => $target->bus?->seating_capacity ?? 0,
                'estimated_savings' => $this->estimatedSavings($source),
                'divergence_stop_id' => $divergence?->getKey(),
                'divergence_sequence' => $divergence?->sequence_number,
                'proposed_by_id' => $actor->getKey(),
                'expires_at' => $this->expiryFor($source),
            ])->save();

            $this->audit->log(
                action: 'CONSOLIDATION_PROPOSED',
                table: $consolidation->getTable(),
                recordId: (string) $consolidation->getKey(),
                new: [
                    'source_trip_id' => (string) $source->getKey(),
                    'target_trip_id' => (string) $target->getKey(),
                    'combined_passengers' => $consolidation->combinedPassengers(),
                    'target_capacity' => $consolidation->target_capacity,
                ],
                actor: $actor,
            );

            ConsolidationProposed::dispatch(
                $consolidation->fresh(['sourceTrip.route', 'targetTrip.route']),
            );

            return $consolidation;
        });
    }

    /**
     * BR-361 — only a manager may approve. The role gate lives in the policy;
     * what lives here is everything that must still be true at commit time.
     *
     * @throws BusinessRuleException
     */
    public function approve(TripConsolidation $consolidation, User $actor): TripConsolidation
    {
        return DB::transaction(function () use ($consolidation, $actor) {
            $consolidation = TripConsolidation::whereKey($consolidation->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($consolidation, ConsolidationStatus::APPROVED);

            if ($consolidation->hasLapsed()) {
                throw new BusinessRuleException(
                    'This proposal has expired and must be re-proposed against current occupancy.',
                );
            }

            // BR-362 re-checked at approval: people boarded while it sat in
            // the queue, and the figures captured at proposal time may no
            // longer add up.
            $this->assertStillFits($consolidation);

            $consolidation->forceFill([
                'status' => ConsolidationStatus::APPROVED,
                'decided_by_id' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'CONSOLIDATION_APPROVED',
                table: $consolidation->getTable(),
                recordId: (string) $consolidation->getKey(),
                new: ['approved_by' => (string) $actor->getKey()],
                actor: $actor,
            );

            return $consolidation;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function reject(TripConsolidation $consolidation, string $reason, User $actor): TripConsolidation
    {
        return DB::transaction(function () use ($consolidation, $reason, $actor) {
            $consolidation = TripConsolidation::whereKey($consolidation->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($consolidation, ConsolidationStatus::REJECTED);

            $consolidation->forceFill([
                'status' => ConsolidationStatus::REJECTED,
                'rejection_reason' => $reason,
                'decided_by_id' => $actor->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'CONSOLIDATION_REJECTED',
                table: $consolidation->getTable(),
                recordId: (string) $consolidation->getKey(),
                new: ['reason' => $reason],
                actor: $actor,
            );

            return $consolidation;
        });
    }

    /**
     * BR-363 — tell the affected passengers, and record that they were told.
     *
     * This is a distinct step from execution because the ordering is the whole
     * rule: being told your bus was cancelled after it was cancelled is not a
     * notification, it is an apology.
     *
     * @throws BusinessRuleException
     */
    public function notifyPassengers(TripConsolidation $consolidation, User $actor): TripConsolidation
    {
        return DB::transaction(function () use ($consolidation, $actor) {
            $consolidation = TripConsolidation::whereKey($consolidation->getKey())
                ->lockForUpdate()->firstOrFail();

            if ($consolidation->status !== ConsolidationStatus::APPROVED) {
                throw new BusinessRuleException(
                    'Passengers are told once the merge is approved, not before — '
                    .'warning people about something that may never happen is its own harm.',
                );
            }

            if ($consolidation->passengersHaveBeenTold()) {
                return $consolidation;
            }

            $consolidation->forceFill(['passengers_notified_at' => now()])->save();

            $this->audit->log(
                action: 'CONSOLIDATION_PASSENGERS_NOTIFIED',
                table: $consolidation->getTable(),
                recordId: (string) $consolidation->getKey(),
                actor: $actor,
            );

            ConsolidationExecuted::dispatch(
                $consolidation->fresh(['sourceTrip.route', 'targetTrip.route', 'targetTrip.bus']),
                pending: true,
            );

            return $consolidation;
        });
    }

    /**
     * Stand the source trip down and move its passengers onto the target.
     *
     * @throws BusinessRuleException
     */
    public function execute(TripConsolidation $consolidation, User $actor): TripConsolidation
    {
        return DB::transaction(function () use ($consolidation, $actor) {
            $consolidation = TripConsolidation::whereKey($consolidation->getKey())
                ->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($consolidation, ConsolidationStatus::EXECUTED);

            // BR-363 — the notification is a precondition, not a side effect.
            if (! $consolidation->passengersHaveBeenTold()) {
                throw new BusinessRuleException(
                    'The affected passengers have not been notified yet. '
                    .'A merge cannot take effect before the people it strands are told.',
                );
            }

            $source = Trip::whereKey($consolidation->source_trip_id)->lockForUpdate()->firstOrFail();
            $target = Trip::whereKey($consolidation->target_trip_id)->lockForUpdate()->firstOrFail();

            $this->assertNotPastDivergence($consolidation, $source);
            $this->assertStillFits($consolidation);

            if ($source->isTerminal()) {
                throw new BusinessRuleException(
                    "The source trip is already {$source->status->value}.",
                );
            }

            if ($target->isTerminal()) {
                throw new BusinessRuleException(
                    "The target trip is already {$target->status->value} and cannot absorb another.",
                );
            }

            $sourcePassengers = $source->occupied_seat_count;

            // The source is stood down, but keeps a pointer to where its
            // passengers went, so anyone following the old trip is redirected
            // rather than left looking at a cancelled journey.
            $source->forceFill([
                'status' => TripStatus::CANCELLED,
                'cancellation_reason' => 'Merged into trip '.$target->getKey(),
                'cancelled_by_id' => $actor->getKey(),
                'cancelled_at' => now(),
                'merged_into_trip_id' => $target->getKey(),
            ])->save();

            $target->forceFill([
                'occupied_seat_count' => $target->occupied_seat_count + $sourcePassengers,
                'booked_seat_count' => $target->booked_seat_count + $source->booked_seat_count,
            ])->save();

            $consolidation->forceFill([
                'status' => ConsolidationStatus::EXECUTED,
                'executed_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'CONSOLIDATION_EXECUTED',
                table: $consolidation->getTable(),
                recordId: (string) $consolidation->getKey(),
                new: [
                    'source_trip_id' => (string) $source->getKey(),
                    'target_trip_id' => (string) $target->getKey(),
                    'passengers_moved' => $sourcePassengers,
                ],
                actor: $actor,
            );

            return $consolidation;
        });
    }

    /**
     * BG-11 — proposals nobody decided must not execute on stale figures.
     *
     * @return int how many lapsed
     */
    public function expireLapsedProposals(): int
    {
        $lapsed = TripConsolidation::lapsed()->get();

        foreach ($lapsed as $consolidation) {
            $consolidation->forceFill(['status' => ConsolidationStatus::EXPIRED])->save();

            $this->audit->log(
                action: 'CONSOLIDATION_EXPIRED',
                table: $consolidation->getTable(),
                recordId: (string) $consolidation->getKey(),
                new: ['expired_at' => now()->toIso8601String()],
            );
        }

        return $lapsed->count();
    }

    /**
     * Find trip pairs worth merging today.
     *
     * @return array<int, array{source: Trip, target: Trip}>
     */
    public function findCandidates(?float $occupancyThreshold = null): array
    {
        $threshold = $occupancyThreshold ?? (float) config('ctms.consolidation.occupancy_threshold', 0.4);

        $trips = Trip::with(['bus', 'route.stops'])
            ->whereIn('status', [TripStatus::SCHEDULED->value, TripStatus::RUNNING->value])
            ->whereDate('trip_date', today())
            ->get()
            ->filter(fn (Trip $trip) => $trip->bus !== null && $trip->bus->seating_capacity > 0)
            ->filter(fn (Trip $trip) => $this->occupancyRatio($trip) < $threshold);

        $pairs = [];
        $claimed = [];

        foreach ($trips as $source) {
            if (in_array((string) $source->getKey(), $claimed, true)) {
                continue;
            }

            foreach ($trips as $target) {
                if ($source->is($target)) {
                    continue;
                }

                if (in_array((string) $target->getKey(), $claimed, true)) {
                    continue;
                }

                if (! $this->pairIsViable($source, $target)) {
                    continue;
                }

                $pairs[] = ['source' => $source, 'target' => $target];
                $claimed[] = (string) $source->getKey();
                $claimed[] = (string) $target->getKey();

                break;
            }
        }

        return $pairs;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    private function occupancyRatio(Trip $trip): float
    {
        $capacity = $trip->bus?->seating_capacity ?? 0;

        return $capacity > 0 ? $trip->occupied_seat_count / $capacity : 1.0;
    }

    /**
     * A pair is viable if everyone fits and the routes actually meet.
     */
    private function pairIsViable(Trip $source, Trip $target): bool
    {
        $capacity = $target->bus?->seating_capacity ?? 0;

        if ($source->occupied_seat_count + $target->occupied_seat_count > $capacity) {
            return false;
        }

        if ($this->divergenceStop($source, $target) === null) {
            return false;
        }

        return ! TripConsolidation::open()
            ->where(function ($query) use ($source, $target) {
                $query->whereIn('source_trip_id', [$source->getKey(), $target->getKey()])
                    ->orWhereIn('target_trip_id', [$source->getKey(), $target->getKey()]);
            })
            ->exists();
    }

    /**
     * BR-364 — the last stop on the source route that the target route also
     * serves. Past it, the target bus is on different roads and cannot pick
     * up the people the source was going to collect.
     */
    private function divergenceStop(Trip $source, Trip $target): ?RouteStop
    {
        $sourceStops = $this->stopsOf($source);
        $targetStops = $this->stopsOf($target);

        if ($sourceStops->isEmpty() || $targetStops->isEmpty()) {
            return null;
        }

        $shared = $sourceStops->filter(
            fn (RouteStop $stop) => $targetStops->contains(
                fn (RouteStop $other) => $this->isSamePlace($stop, $other),
            ),
        );

        return $shared->sortByDesc('sequence_number')->first();
    }

    /**
     * @return Collection<int, RouteStop>
     */
    private function stopsOf(Trip $trip): Collection
    {
        return $trip->route?->stops()->orderBy('sequence_number')->get() ?? collect();
    }

    private function isSamePlace(RouteStop $a, RouteStop $b): bool
    {
        return $this->metresBetween(
            (float) $a->latitude, (float) $a->longitude,
            (float) $b->latitude, (float) $b->longitude,
        ) <= self::SAME_STOP_METRES;
    }

    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_000;

        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad($lng2) - deg2rad($lng1);

        $a = sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertProposable(Trip $source, Trip $target): void
    {
        if ($source->is($target)) {
            throw new BusinessRuleException('A trip cannot be merged into itself.');
        }

        if ($source->isTerminal() || $target->isTerminal()) {
            throw new BusinessRuleException(
                'Both trips must still be active for a merge to be worth proposing.',
            );
        }

        if (! $source->trip_date->isSameDay($target->trip_date)) {
            throw new BusinessRuleException(
                'Trips on different days cannot be merged.',
            );
        }

        if ($target->bus === null) {
            throw new BusinessRuleException(
                'The target trip has no bus, so there is nothing to merge onto.',
            );
        }

        $combined = $source->occupied_seat_count + $target->occupied_seat_count;

        if (! TripConsolidation::fits(
            $source->occupied_seat_count,
            $target->occupied_seat_count,
            $target->bus->seating_capacity,
        )) {
            throw new BusinessRuleException(
                "The combined {$combined} passengers exceed the target bus's "
                ."{$target->bus->seating_capacity} seats.",
                ['combined' => $combined, 'capacity' => $target->bus->seating_capacity],
            );
        }

        if ($this->divergenceStop($source, $target) === null) {
            throw new BusinessRuleException(
                'These routes share no stops, so one bus cannot serve both.',
            );
        }

        $existing = TripConsolidation::open()
            ->where('source_trip_id', $source->getKey())
            ->exists();

        if ($existing) {
            throw new BusinessRuleException(
                'This trip already has an open consolidation proposal.',
            );
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertStillFits(TripConsolidation $consolidation): void
    {
        $source = $consolidation->sourceTrip;
        $target = $consolidation->targetTrip;

        if ($source === null || $target === null) {
            throw new BusinessRuleException('One of the trips in this proposal no longer exists.');
        }

        $capacity = $target->bus?->seating_capacity ?? 0;
        $combined = $source->occupied_seat_count + $target->occupied_seat_count;

        if (! TripConsolidation::fits(
            $source->occupied_seat_count,
            $target->occupied_seat_count,
            $capacity,
        )) {
            throw new BusinessRuleException(
                "The combined {$combined} passengers no longer fit on the target bus's {$capacity} seats.",
                ['combined' => $combined, 'capacity' => $capacity],
            );
        }
    }

    /**
     * BR-364.
     *
     * @throws BusinessRuleException
     */
    private function assertNotPastDivergence(TripConsolidation $consolidation, Trip $source): void
    {
        if ($consolidation->divergence_sequence === null) {
            return;
        }

        $furthestReached = $source->stopProgress()
            ->whereIn('state', ['ARRIVED', 'DEPARTED', 'SKIPPED'])
            ->max('sequence_number');

        if ($furthestReached === null) {
            return;
        }

        if ((int) $furthestReached >= $consolidation->divergence_sequence) {
            throw new BusinessRuleException(
                'This bus has already passed the point where the two routes part company. '
                .'Merging now would strand the passengers still waiting further along it.',
                [
                    'divergence_sequence' => $consolidation->divergence_sequence,
                    'reached_sequence' => (int) $furthestReached,
                ],
            );
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCanTransition(TripConsolidation $consolidation, ConsolidationStatus $target): void
    {
        if (! $consolidation->status->canTransitionTo($target)) {
            throw new BusinessRuleException(
                "A consolidation cannot go from {$consolidation->status->value} to {$target->value}.",
                ['from' => $consolidation->status->value, 'to' => $target->value],
            );
        }
    }

    private function defaultReason(Trip $source, Trip $target): string
    {
        $sourcePercent = (int) round($this->occupancyRatio($source) * 100);
        $targetPercent = (int) round($this->occupancyRatio($target) * 100);

        return "Both trips are running well below capacity ({$sourcePercent}% and "
            ."{$targetPercent}%) over overlapping roads.";
    }

    private function estimatedSavings(Trip $source): float
    {
        $perKm = (float) config('ctms.consolidation.cost_per_km', 18.0);
        $distance = (float) ($source->route?->total_distance_km ?? 0);

        return round($perKm * $distance, 2);
    }

    /**
     * A proposal is only good until the bus it concerns is due to leave.
     */
    private function expiryFor(Trip $source): CarbonInterface
    {
        $window = (int) config('ctms.consolidation.decision_window_minutes', 30);

        return now()->addMinutes($window);
    }
}
