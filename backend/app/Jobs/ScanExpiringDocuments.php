<?php

namespace App\Jobs;

use App\Events\Fleet\DocumentExpiryWarning;
use App\Models\BusDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * BG-14 — daily scan for lapsing and lapsed vehicle documents (N-22, N-23).
 *
 * Idempotent by construction: warnings fire only on the configured day
 * thresholds, and the notification dedup key includes the threshold, so
 * running the scan twice in a day tells nobody twice.
 */
class ScanExpiringDocuments implements ShouldQueue
{
    use Queueable;

    /**
     * Days before expiry at which a warning is sent. Matches the blueprint's
     * N-22 schedule.
     */
    private const THRESHOLDS = [30, 14, 7, 1];

    public function handle(): void
    {
        $warned = 0;
        $expired = 0;

        // Warn ahead of the lapse.
        foreach (self::THRESHOLDS as $days) {
            $documents = BusDocument::with('bus')
                ->current()
                ->whereDate('expires_on', now()->addDays($days)->toDateString())
                ->get();

            foreach ($documents as $document) {
                if ($document->document_type->isMandatory()) {
                    DocumentExpiryWarning::dispatch($document, $days);
                    $warned++;
                }
            }
        }

        // And report the ones already lapsed, which are blocking today.
        $lapsed = BusDocument::with('bus')->current()->expired()->get();

        foreach ($lapsed as $document) {
            if ($document->document_type->isMandatory()) {
                DocumentExpiryWarning::dispatch($document, -1);
                $expired++;
            }
        }

        Log::info('Document expiry scan complete', [
            'warnings_sent' => $warned,
            'expired_reported' => $expired,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Document expiry scan failed', ['error' => $exception->getMessage()]);
    }
}
