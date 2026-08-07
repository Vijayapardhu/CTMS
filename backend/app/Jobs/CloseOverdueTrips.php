<?php

namespace App\Jobs;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\Trips\TripService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-09 — close trips left running past their arrival buffer (BR-260).
 *
 * A driver who forgets to end a trip leaves a bus apparently on the road all
 * night. Closing it keeps the fleet state honest; flagging it as auto-closed
 * keeps the punctuality figures honest (BR-261).
 */
class CloseOverdueTrips implements ShouldQueue
{
    use Queueable;

    public function handle(TripService $trips): void
    {
        $closed = 0;

        $running = Trip::with(['bus', 'driver'])
            ->where('status', TripStatus::RUNNING->value)
            ->get();

        foreach ($running as $trip) {
            if (! $trip->isOverdueForClosure()) {
                continue;
            }

            try {
                $trips->autoClose($trip);
                $closed++;
            } catch (\Throwable $e) {
                // One stuck trip must not stop the others being tidied up.
                Log::error('Failed to auto-close an overdue trip', [
                    'trip_id' => (string) $trip->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($closed > 0) {
            Log::info('Overdue trips closed automatically', ['count' => $closed]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Overdue trip closure job failed', ['error' => $exception->getMessage()]);
    }
}
