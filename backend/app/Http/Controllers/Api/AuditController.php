<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Governance\SubjectAccessRequest;
use App\Models\AuditLog;
use App\Models\DataAccessLog;
use App\Models\User;
use App\Services\Governance\DataAccessLogger;
use App\Services\Governance\RetentionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail, the access record, and subject access requests
 * (FR-15, BR-501, BR-502, BR-506, BR-507).
 *
 * Read-only by design. There is no write endpoint on this controller and there
 * must never be one: the audit trail is only evidence for as long as nobody
 * can reach in and adjust it.
 */
class AuditController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DataAccessLogger $accessLog,
        private readonly RetentionService $retention,
    ) {}

    /**
     * GET /api/v1/audit-logs
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'action' => ['sometimes', 'string', 'max:100'],
            'table_name' => ['sometimes', 'string', 'max:100'],
            'record_id' => ['sometimes', 'uuid'],
            'user_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::with('user');

        foreach (['action', 'table_name', 'record_id', 'user_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $this->paginated(
            $query->latest('created_at')->paginate($this->perPage($filters['per_page'] ?? null)),
            'Audit log retrieved successfully.',
        );
    }

    /**
     * GET /api/v1/audit-logs/{id}
     */
    public function show(string $id): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $log = AuditLog::with('user')->find($id);

        if (! $log) {
            throw new ResourceNotFoundException('Audit record not found.');
        }

        return $this->success($log, 'Audit record retrieved successfully.');
    }

    /**
     * GET /api/v1/data-access-logs
     *
     * BR-501 — who has been reading students' personal data.
     */
    public function accessLogs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'subject_id' => ['sometimes', 'uuid'],
            'user_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'bulk' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DataAccessLog::with('user');

        if (isset($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if ($request->boolean('bulk')) {
            $query->bulk();
        }

        return $this->paginated(
            $query->latest('created_at')->paginate($this->perPage($filters['per_page'] ?? null)),
            'Data access log retrieved successfully.',
        );
    }

    /**
     * POST /api/v1/users/{id}/subject-access-export
     *
     * BR-506 — everything the system holds about one person.
     *
     * A POST rather than a GET because it is not a read: it produces a copy of
     * somebody's entire record and writes a high-visibility access entry. A
     * GET would end up in a browser history, a proxy log and a bookmark.
     */
    public function subjectAccessExport(SubjectAccessRequest $request, string $id): JsonResponse
    {
        $this->authorize('export', AuditLog::class);

        $subject = User::find($id);

        if (! $subject) {
            throw new ResourceNotFoundException('User not found.');
        }

        $export = $this->retention->subjectAccessExport($subject);

        // BR-502 — the export is itself an access, and a notable one.
        $this->accessLog->recordBulkExport(
            actor: $request->user(),
            subjectType: 'user',
            dataClass: 'SUBJECT_ACCESS_REQUEST',
            recordCount: count($export['journeys']) + count($export['notifications']),
            reason: $request->validated()['reason'],
        );

        return $this->success($export, 'Subject access export generated.');
    }

    /**
     * GET /api/v1/retention-runs
     */
    public function retentionRuns(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->paginated(
            DB::table('retention_runs')
                ->orderByDesc('created_at')
                ->paginate($this->perPage($validated['per_page'] ?? null)),
            'Retention runs retrieved successfully.',
        );
    }
}
