<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Trips\ConsolidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-11 — hourly consolidation analysis (FR-13).
 *
 * Proposes merges for low-occupancy trips and expires proposals nobody
 * decided. Both halves matter: a stale proposal executed tomorrow on
 * yesterday's occupancy would cancel a bus that is now full.
 */
class ProposeConsolidations implements ShouldQueue
{
    use Queueable;

    public function handle(ConsolidationService $consolidations): void
    {
        $expired = $consolidations->expireLapsedProposals();

        if ($expired > 0) {
            Log::info('Consolidation proposals expired undecided', ['count' => $expired]);
        }

        // BR-512 — the system acts as an attributable actor. It proposes under
        // a real identity so the audit trail never says "somebody".
        $actor = User::systemActor();

        if ($actor === null) {
            Log::warning('Consolidation analysis skipped: no system actor is configured.');

            return;
        }

        $proposed = 0;

        foreach ($consolidations->findCandidates() as $pair) {
            try {
                $consolidations->propose($pair['source'], $pair['target'], $actor);
                $proposed++;
            } catch (\Throwable $e) {
                // One unviable pair must not stop the sweep. It is logged
                // rather than swallowed, so a systematic failure is visible.
                Log::info('Consolidation candidate rejected during proposal', [
                    'source_trip_id' => (string) $pair['source']->getKey(),
                    'target_trip_id' => (string) $pair['target']->getKey(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        if ($proposed > 0) {
            Log::info('Consolidation proposals raised', ['count' => $proposed]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Consolidation analysis failed', ['error' => $exception->getMessage()]);
    }
}
