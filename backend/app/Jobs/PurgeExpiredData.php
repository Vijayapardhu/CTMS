<?php

namespace App\Jobs;

use App\Services\Governance\RetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-19 — daily retention purge (BR-504, BR-307).
 *
 * Runs a dry pass first and logs what it would remove, then removes it. The
 * dry pass is not ceremony: it is the only record of what a purge was about to
 * do, and the only thing that makes a wrong retention window visible before it
 * has already deleted a term's worth of traces.
 */
class PurgeExpiredData implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly bool $dryRunOnly = false) {}

    public function handle(RetentionService $retention): void
    {
        foreach ($retention->purgeAll(dryRun: true) as $plan) {
            Log::info('Retention purge planned', $plan);
        }

        if ($this->dryRunOnly) {
            return;
        }

        foreach ($retention->purgeAll(dryRun: false) as $result) {
            Log::info('Retention purge completed', $result);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Retention purge failed', ['error' => $exception->getMessage()]);
    }
}
