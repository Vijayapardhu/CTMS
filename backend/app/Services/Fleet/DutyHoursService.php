<?php

namespace App\Services\Fleet;

use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Driver;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * BR-106 — duty-hour ceilings: max continuous, max daily, min rest.
 *
 * This is a fatigue rule, and fatigue is the failure mode nobody reports. A
 * driver will not tell you they are too tired to drive on the morning a
 * colleague has called in sick; the roster has to refuse for them.
 *
 * Everything is measured from trips actually driven — departure to arrival on
 * completed runs — rather than from a shift table somebody maintains by hand,
 * because the hand-maintained one is always the optimistic one.
 */
class DutyHoursService
{
    /**
     * Every ceiling this driver is currently against.
     *
     * @return array<int, string> empty when they are clear to drive
     */
    public function breachesFor(Driver $driver, ?CarbonInterface $at = null): array
    {
        $at = $at ?? now();
        $breaches = [];

        $today = $this->drivenMinutesOn($driver, $at);
        $dailyCeiling = (int) config('ctms.duty.max_daily_minutes', 540);

        if ($today >= $dailyCeiling) {
            $breaches[] = sprintf(
                'Daily driving limit reached: %d of %d minutes already driven today.',
                $today,
                $dailyCeiling,
            );
        }

        $continuous = $this->continuousMinutes($driver, $at);
        $continuousCeiling = (int) config('ctms.duty.max_continuous_minutes', 270);

        if ($continuous >= $continuousCeiling) {
            $breaches[] = sprintf(
                'Continuous driving limit reached: %d of %d minutes without a break.',
                $continuous,
                $continuousCeiling,
            );
        }

        $restMinutes = $this->minutesSinceLastTrip($driver, $at);
        $restRequired = (int) config('ctms.duty.min_rest_minutes', 600);

        // Minimum rest is a between-shifts rule, not a between-trips one. It
        // is measured only against duty that ended on a previous day, because
        // the gap between two runs of the same shift is a break — governed by
        // the continuous-driving ceiling above — and treating it as rest would
        // block a driver from their second run of the morning.
        if ($restMinutes !== null && $restMinutes < $restRequired) {
            $breaches[] = sprintf(
                'Minimum rest not met: %d of %d minutes since the previous day\'s duty ended.',
                $restMinutes,
                $restRequired,
            );
        }

        return $breaches;
    }

    /**
     * @throws BusinessRuleException
     */
    public function assertWithinDutyLimits(Driver $driver, ?CarbonInterface $at = null): void
    {
        $breaches = $this->breachesFor($driver, $at);

        if ($breaches === []) {
            return;
        }

        throw new BusinessRuleException(
            'This driver is over their duty-hour limits and cannot take another trip. '
            .implode(' ', $breaches),
            ['duty_breaches' => $breaches],
        );
    }

    /**
     * Minutes driven on the given day, from completed trips.
     */
    public function drivenMinutesOn(Driver $driver, CarbonInterface $at): int
    {
        $minutes = 0;

        foreach ($this->tripsOn($driver, $at) as $trip) {
            $minutes += $this->minutesOf($trip);
        }

        return $minutes;
    }

    /**
     * Minutes driven without a qualifying break.
     *
     * A gap of at least the configured break length resets the run — which is
     * what a break is.
     */
    public function continuousMinutes(Driver $driver, CarbonInterface $at): int
    {
        $breakMinutes = (int) config('ctms.duty.qualifying_break_minutes', 30);

        $trips = $this->tripsOn($driver, $at)
            ->sortBy(fn (Trip $trip) => $this->startedAt($trip))
            ->values();

        $run = 0;
        $previousEnd = null;

        foreach ($trips as $trip) {
            $start = $this->startedAt($trip);

            if ($previousEnd !== null && $previousEnd->diffInMinutes($start) >= $breakMinutes) {
                $run = 0;
            }

            $run += $this->minutesOf($trip);
            $previousEnd = $this->endedAt($trip);
        }

        return $run;
    }

    /**
     * Minutes since this driver's last *previous-day* duty ended, or null if
     * they have none.
     *
     * Today's trips are excluded on purpose — see the rest rule above.
     */
    public function minutesSinceLastTrip(Driver $driver, CarbonInterface $at): ?int
    {
        $last = Trip::where('driver_id', $driver->getKey())
            ->where('status', TripStatus::COMPLETED->value)
            ->whereNotNull('actual_arrival_time')
            ->whereDate('trip_date', '<', $at->toDateString())
            ->orderByDesc('trip_date')
            ->orderByDesc('actual_arrival_time')
            ->first();

        if ($last === null) {
            return null;
        }

        $endedAt = $this->endedAt($last);

        return $endedAt->isAfter($at) ? 0 : (int) $endedAt->diffInMinutes($at);
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * @return Collection<int, Trip>
     */
    private function tripsOn(Driver $driver, CarbonInterface $at): Collection
    {
        return Trip::where('driver_id', $driver->getKey())
            ->whereIn('status', [TripStatus::COMPLETED->value, TripStatus::RUNNING->value])
            ->whereDate('trip_date', $at->toDateString())
            ->whereNotNull('actual_departure_time')
            ->get();
    }

    private function minutesOf(Trip $trip): int
    {
        return max(0, (int) $this->startedAt($trip)->diffInMinutes($this->endedAt($trip)));
    }

    private function startedAt(Trip $trip): CarbonInterface
    {
        return $trip->trip_date->copy()->setTimeFromTimeString($trip->actual_departure_time);
    }

    /**
     * A trip still running counts up to now — a driver eight hours into a run
     * that has not finished is exactly the case this rule exists for.
     */
    private function endedAt(Trip $trip): CarbonInterface
    {
        if ($trip->actual_arrival_time === null) {
            return now();
        }

        return $trip->trip_date->copy()->setTimeFromTimeString($trip->actual_arrival_time);
    }
}
