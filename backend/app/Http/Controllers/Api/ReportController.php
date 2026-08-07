<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Reports\ReportService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operational reports (FR-15).
 *
 * Every report is aggregate. None of them return a named student, a route
 * position or anything else that would let this endpoint become a way around
 * BR-500 — a report is a count, not a lookup.
 */
class ReportController extends Controller
{
    use ApiResponse;

    /**
     * The longest window a single report may cover. Beyond this the query
     * stops being a report and starts being an export, which has its own rules
     * (BR-502).
     */
    private const MAX_WINDOW_DAYS = 400;

    public function __construct(private readonly ReportService $reports) {}

    /**
     * GET /api/v1/reports/trips
     */
    public function trips(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return $this->success($this->reports->tripSummary($from, $to), 'Trip report generated.');
    }

    /**
     * GET /api/v1/reports/occupancy
     */
    public function occupancy(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return $this->success($this->reports->occupancy($from, $to), 'Occupancy report generated.');
    }

    /**
     * GET /api/v1/reports/fleet
     */
    public function fleet(Request $request): JsonResponse
    {
        $this->authorizeReporting($request);

        return $this->success($this->reports->fleet(), 'Fleet report generated.');
    }

    /**
     * GET /api/v1/reports/incidents
     */
    public function incidents(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return $this->success($this->reports->incidents($from, $to), 'Incident report generated.');
    }

    /**
     * GET /api/v1/reports/attendance
     */
    public function attendance(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return $this->success($this->reports->attendance($from, $to), 'Attendance report generated.');
    }

    /**
     * GET /api/v1/reports/maintenance
     */
    public function maintenance(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        return $this->success($this->reports->maintenance($from, $to), 'Maintenance report generated.');
    }

    /**
     * Validate and bound the reporting window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        $this->authorizeReporting($request);

        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->copy()->subDays(30)->startOfDay();

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();

        // An unbounded window is how a report becomes a full-table scan on a
        // production database at nine in the morning.
        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $from = $to->copy()->subDays(self::MAX_WINDOW_DAYS);
        }

        return [$from, $to];
    }

    private function authorizeReporting(Request $request): void
    {
        $this->authorize('viewAny', AuditLog::class);
    }
}
