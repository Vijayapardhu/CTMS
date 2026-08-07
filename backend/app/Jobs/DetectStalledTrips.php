<?php

namespace App\Jobs;

use App\Enums\IncidentType;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\VehicleIncident;
use App\Services\Incidents\IncidentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-08, BR-259 — a running trip that has stopped reporting its position.
 *
 * A bus that vanishes from the live map has either lost signal, run out of
 * battery, or had something happen to it. The system cannot tell which, and
 * that is exactly why a human has to look.
 */
class DetectStalledTrips implements ShouldQueue
{
    use Queueable;

    public function handle(IncidentService $incidents): void
    {
        $threshold = (int) config('ctms.gps.stall_after_seconds', 600);
        $flagged = 0;

        $running = Trip::with(['bus', 'driver.user', 'route'])
            ->where('status', TripStatus::RUNNING->value)
            ->get();

        foreach ($running as $trip) {
            // A trip that has never reported is measured from its departure,
            // so a driver who starts a trip and immediately loses signal is
            // still caught.
            $lastSignal = $trip->last_gps_update ?? $trip->updated_at;

            if ($lastSignal === null || $lastSignal->diffInSeconds(now()) < $threshold) {
                continue;
            }

            if ($this->alreadyFlagged($trip)) {
                continue;
            }

            try {
                $reporter = $trip->driver?->user;

                if ($reporter === null) {
                    continue;
                }

                $minutes = (int) $lastSignal->diffInMinutes(now());

                $incidents->report([
                    'incident_type' => IncidentType::TRACKING_LOST->value,
                    'description' => "No position received for {$minutes} minutes. The bus may have lost signal, or something may have happened to it — contact the driver.",
                    // A stalled trip is a service-class signal for a human to
                    // check, not an assertion that anything is wrong. Raising
                    // it as life-safety would cry wolf on every tunnel.
                    'idempotency_key' => 'stalled:'.$trip->getKey().':'.$lastSignal->timestamp,
                    'vehicle_can_continue' => true,
                ], $reporter, $trip);

                $flagged++;
            } catch (\Throwable $e) {
                Log::error('Failed to flag a stalled trip', [
                    'trip_id' => (string) $trip->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($flagged > 0) {
            Log::warning('Stalled trips flagged for review', ['count' => $flagged]);
        }
    }

    /**
     * One open stall report per trip — a bus in a long tunnel must not
     * generate an incident every minute.
     */
    private function alreadyFlagged(Trip $trip): bool
    {
        return VehicleIncident::where('trip_id', $trip->getKey())
            ->where('incident_type', IncidentType::TRACKING_LOST->value)
            ->open()
            ->exists();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Stalled trip detection failed', ['error' => $exception->getMessage()]);
    }
}
