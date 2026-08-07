<?php

namespace App\Jobs;

use App\Services\Trips\TripGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * BG-01 — generate tomorrow's trips.
 *
 * Runs nightly. Idempotent per (schedule, date), so a re-run after a failure
 * is safe and is the documented recovery.
 */
class GenerateDailyTrips implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $date = null) {}

    public function handle(TripGenerationService $generator): void
    {
        $date = $this->date !== null
            ? Carbon::parse($this->date)
            : Carbon::tomorrow();

        $result = $generator->generateFor($date);

        Log::info('Daily trip generation complete', $result);

        if ($result['exceptions'] !== []) {
            // BG-02 — surfaced for the morning review, never auto-resolved.
            Log::warning('Trip generation produced exceptions requiring review', [
                'date' => $result['date'],
                'count' => count($result['exceptions']),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Daily trip generation failed', [
            'date' => $this->date,
            'error' => $exception->getMessage(),
        ]);
    }
}
