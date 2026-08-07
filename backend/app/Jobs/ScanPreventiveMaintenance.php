<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-16 — daily preventive maintenance scan (BR-366).
 *
 * Opens a ticket for every service that has fallen due, by date or by
 * distance. It runs before the morning departures so a service falling due
 * today is visible while there is still time to swap the vehicle.
 */
class ScanPreventiveMaintenance implements ShouldQueue
{
    use Queueable;

    public function handle(MaintenanceService $maintenance): void
    {
        // BR-512 — the scan acts under the system identity, so the tickets it
        // raises are attributable like anyone else's.
        $raised = $maintenance->raiseDuePreventiveTickets(User::systemActor());

        if ($raised > 0) {
            Log::info('Preventive maintenance tickets raised', ['count' => $raised]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Preventive maintenance scan failed', ['error' => $exception->getMessage()]);
    }
}
