<?php

namespace App\Jobs;

use App\Models\VehicleIncident;
use App\Services\Incidents\IncidentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BR-356 — escalate incidents nobody has acknowledged.
 *
 * Runs every minute. A life-safety incident tolerates two minutes of silence;
 * anything longer is indistinguishable, from the outside, from an incident
 * nobody ever saw.
 */
class EscalateUnacknowledgedIncidents implements ShouldQueue
{
    use Queueable;

    public function handle(IncidentService $incidents): void
    {
        $escalated = 0;

        $candidates = VehicleIncident::with(['bus', 'trip.route', 'driver.user'])
            ->unacknowledged()
            ->get();

        foreach ($candidates as $incident) {
            if (! $incident->isOverdueForEscalation()) {
                continue;
            }

            try {
                $incidents->escalate($incident);
                $escalated++;
            } catch (\Throwable $e) {
                // One stuck incident must not stop the others escalating.
                Log::error('Failed to escalate an incident', [
                    'incident_id' => (string) $incident->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($escalated > 0) {
            Log::warning('Unacknowledged incidents escalated', ['count' => $escalated]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Incident escalation job failed', ['error' => $exception->getMessage()]);
    }
}
