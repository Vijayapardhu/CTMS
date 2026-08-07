<?php

namespace App\Services\Governance;

use App\Enums\TripStatus;
use App\Models\DataAccessLog;
use App\Models\EvidenceFile;
use App\Models\Notification;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\User;
use App\Services\Evidence\EvidenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BG-19 — retention purge (BR-504, BR-505, BR-307).
 *
 * Two rules govern everything here and they pull in opposite directions.
 * BR-307 says the raw location traces of minors are deleted on schedule, which
 * is the whole reason this exists. BR-505 says a purge refuses to run where it
 * would break referential history — a trip that no longer has an attendance
 * record cannot answer "was my child on that bus", and no retention policy is
 * worth losing that answer.
 *
 * The resolution: only the fine-grained trace is purged. The trip, its
 * attendance and its incidents survive. Somebody can still be shown that their
 * child boarded at 07:42 and got off at 08:15; what disappears is the
 * second-by-second breadcrumb of exactly which roads the bus took.
 */
class RetentionService
{
    /**
     * Purge everything past its window.
     *
     * @return array<int, array<string, mixed>> one entry per data class
     */
    public function purgeAll(bool $dryRun = false): array
    {
        return [
            $this->purgeLocationTraces($dryRun),
            $this->purgeNotifications($dryRun),
            $this->purgeOrphanedEvidence($dryRun),
        ];
    }

    /**
     * BR-307 — raw GPS traces. The most sensitive data the system holds and
     * the shortest window.
     *
     * @return array<string, mixed>
     */
    public function purgeLocationTraces(bool $dryRun = false): array
    {
        $days = (int) config('ctms.retention.location_trace_days', 90);
        $cutoff = now()->subDays($days);

        // BR-505 — never touch a trace belonging to a trip that is still
        // running or still unresolved. Purging under a live trip would blind
        // the map mid-journey.
        $matched = TripLocation::where('recorded_at', '<', $cutoff)
            ->whereHas('trip', fn ($query) => $query->whereIn('status', [
                TripStatus::COMPLETED->value,
                TripStatus::CANCELLED->value,
            ]))
            ->whereDoesntHave('trip.discrepancy', fn ($query) => $query->where('status', 'OPEN'))
            ->count();

        if ($dryRun) {
            return $this->record('LOCATION_TRACE', $days, $cutoff, $matched, 0, 'DRY_RUN');
        }

        $purged = 0;

        TripLocation::where('recorded_at', '<', $cutoff)
            ->whereHas('trip', fn ($query) => $query->whereIn('status', [
                TripStatus::COMPLETED->value,
                TripStatus::CANCELLED->value,
            ]))
            ->whereDoesntHave('trip.discrepancy', fn ($query) => $query->where('status', 'OPEN'))
            ->chunkById(500, function ($rows) use (&$purged) {
                $ids = $rows->modelKeys();

                TripLocation::whereIn('id', $ids)->delete();

                $purged += count($ids);
            });

        return $this->record('LOCATION_TRACE', $days, $cutoff, $matched, $purged, 'PURGED');
    }

    /**
     * Delivered notifications past their window. Low risk: the notification
     * log (who was told what, and whether it arrived) is a separate table and
     * is not touched.
     *
     * @return array<string, mixed>
     */
    public function purgeNotifications(bool $dryRun = false): array
    {
        $days = (int) config('ctms.notifications.retention_days', 30);
        $cutoff = now()->subDays($days);

        $matched = Notification::where('created_at', '<', $cutoff)->count();

        if ($dryRun) {
            return $this->record('NOTIFICATION', $days, $cutoff, $matched, 0, 'DRY_RUN');
        }

        $purged = Notification::where('created_at', '<', $cutoff)->delete();

        return $this->record('NOTIFICATION', $days, $cutoff, $matched, (int) $purged, 'PURGED');
    }

    /**
     * Files uploaded and never attached to a report.
     *
     * A driver who photographs damage and then abandons the report leaves the
     * picture behind. Attached evidence is never touched — that is the whole
     * distinction (BR-505).
     *
     * @return array<string, mixed>
     */
    public function purgeOrphanedEvidence(bool $dryRun = false): array
    {
        $hours = (int) config('ctms.retention.orphaned_evidence_hours', 48);
        $cutoff = now()->subHours($hours);

        $matched = EvidenceFile::orphaned()
            ->where('created_at', '<', $cutoff)->count();

        if ($dryRun) {
            return $this->record('ORPHANED_EVIDENCE', (int) ceil($hours / 24), $cutoff, $matched, 0, 'DRY_RUN');
        }

        $purged = app(EvidenceService::class)->purgeOrphans();

        return $this->record('ORPHANED_EVIDENCE', (int) ceil($hours / 24), $cutoff, $matched, $purged, 'PURGED');
    }

    /**
     * BR-506 — everything the system holds about one person, for a subject
     * access request.
     *
     * @return array<string, mixed>
     */
    public function subjectAccessExport(User $subject): array
    {
        $student = $subject->student;

        $trips = $student === null ? collect() : DB::table('passenger_logs')
            ->join('trips', 'passenger_logs.trip_id', '=', 'trips.id')
            ->leftJoin('routes', 'trips.route_id', '=', 'routes.id')
            ->where('passenger_logs.student_id', $student->getKey())
            ->orderByDesc('passenger_logs.recorded_at')
            ->limit(5000)
            ->get([
                'passenger_logs.action',
                'passenger_logs.recorded_at',
                'trips.trip_date',
                'routes.route_name',
            ]);

        return [
            'generated_at' => now()->toIso8601String(),
            'subject' => [
                'id' => (string) $subject->getKey(),
                'email' => $subject->email,
                'first_name' => $subject->first_name,
                'last_name' => $subject->last_name,
                'phone_number' => $subject->phone_number,
                'role' => $subject->role->value,
                'created_at' => $subject->created_at?->toIso8601String(),
            ],
            'transport' => $student === null ? null : [
                'student_id' => (string) $student->getKey(),
                'roll_number' => $student->roll_number,
                'route_id' => (string) $student->route_id,
                'status' => $student->status?->value,
            ],
            'journeys' => $trips->map(fn ($row) => [
                'action' => $row->action,
                'recorded_at' => $row->recorded_at,
                'trip_date' => $row->trip_date,
                'route_name' => $row->route_name,
            ])->all(),
            'notifications' => Notification::where('user_id', $subject->getKey())
                ->latest('created_at')->limit(1000)
                ->get(['event_key', 'title', 'body', 'created_at'])
                ->all(),
            // Deliberately named: the person is entitled to know that reads of
            // their data were recorded, and how many.
            'access_record_count' => DataAccessLog::forSubject(
                'student', (string) $student?->getKey(),
            )->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        string $dataClass,
        int $days,
        \DateTimeInterface $cutoff,
        int $matched,
        int $purged,
        string $outcome,
    ): array {
        $row = [
            'data_class' => $dataClass,
            'retention_days' => $days,
            'outcome' => $outcome,
            'records_matched' => $matched,
            'records_purged' => $purged,
            'cutoff_at' => $cutoff,
        ];

        try {
            DB::table('retention_runs')->insert($row + [
                'id' => (string) Str::uuid7(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record a retention run', [
                'data_class' => $dataClass,
                'error' => $e->getMessage(),
            ]);
        }

        return $row + ['cutoff_at' => $cutoff->format(DATE_ATOM)];
    }
}
