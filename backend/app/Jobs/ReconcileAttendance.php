<?php

namespace App\Jobs;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\Trips\TripRecoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-20, BR-266 — end-of-day attendance reconciliation.
 *
 * Compares each closed trip's headcount against its boarding events and
 * records the ones that disagree. It produces a review queue, not a
 * correction: the system has no way of knowing which number is right, and
 * choosing one would erase the only evidence that a passenger may be
 * unaccounted for.
 */
class ReconcileAttendance implements ShouldQueue
{
    use Queueable;

    public function handle(TripRecoveryService $recovery): void
    {
        $raised = 0;

        Trip::where('status', TripStatus::COMPLETED->value)
            ->whereDate('trip_date', today())
            ->whereDoesntHave('discrepancy')
            ->with('passengerLogs')
            ->chunkById(200, function ($trips) use ($recovery, &$raised) {
                foreach ($trips as $trip) {
                    try {
                        if ($recovery->reconcileAttendance($trip) !== null) {
                            $raised++;
                        }
                    } catch (\Throwable $e) {
                        // One bad trip must not stop the day's reconciliation,
                        // but it is logged rather than swallowed.
                        Log::error('Attendance reconciliation failed for a trip', [
                            'trip_id' => (string) $trip->getKey(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($raised > 0) {
            Log::warning('Attendance discrepancies raised for review', ['count' => $raised]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Attendance reconciliation failed', ['error' => $exception->getMessage()]);
    }
}
