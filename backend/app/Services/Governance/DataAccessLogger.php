<?php

namespace App\Services\Governance;

use App\Models\DataAccessLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * BR-501, BR-502 — records who read a student's personal data.
 *
 * Like the audit trail, this is best-effort in one direction only: a failure
 * to log must not break the read (BR-509), but it is logged loudly, because a
 * silent gap in an access record is indistinguishable from nobody having
 * looked.
 */
class DataAccessLogger
{
    /**
     * A member of staff opened one subject's data.
     */
    public function recordAccess(
        ?User $actor,
        string $subjectType,
        ?string $subjectId,
        string $dataClass,
        string $purpose,
    ): void {
        $this->write($actor, $subjectType, $subjectId, $dataClass, $purpose, false, 1, null);
    }

    /**
     * BR-502 — a bulk export, which requires a stated reason and is flagged
     * for separate review.
     */
    public function recordBulkExport(
        ?User $actor,
        string $subjectType,
        string $dataClass,
        int $recordCount,
        string $reason,
    ): void {
        $this->write($actor, $subjectType, null, $dataClass, 'BULK_EXPORT', true, $recordCount, $reason);

        // A high-visibility entry, per BR-502. Somebody taking a copy of every
        // student's data is not routine and should not read as routine.
        Log::warning('Bulk personal-data export', [
            'actor_id' => (string) $actor?->getKey(),
            'subject_type' => $subjectType,
            'data_class' => $dataClass,
            'record_count' => $recordCount,
            'reason' => $reason,
        ]);
    }

    private function write(
        ?User $actor,
        string $subjectType,
        ?string $subjectId,
        string $dataClass,
        string $purpose,
        bool $isBulk,
        int $recordCount,
        ?string $reason,
    ): void {
        try {
            $entry = new DataAccessLog;

            $entry->forceFill([
                'user_id' => $actor?->getKey(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'data_class' => $dataClass,
                'purpose' => $purpose,
                'is_bulk' => $isBulk,
                'record_count' => $recordCount,
                'reason' => $reason,
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
            ])->save();
        } catch (\Throwable $e) {
            // BR-509 — logging never fails the operation being logged.
            Log::error('Failed to write data access log', [
                'subject_type' => $subjectType,
                'data_class' => $dataClass,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clientIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            // Console and queue contexts have no request.
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            return substr((string) Request::userAgent(), 0, 255) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
