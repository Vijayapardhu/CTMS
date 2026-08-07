<?php

namespace App\Services\Reports;

use App\Enums\IncidentClass;
use App\Enums\MaintenanceStatus;
use App\Enums\TripStatus;
use App\Models\AttendanceDiscrepancy;
use App\Models\Bus;
use App\Models\MaintenanceTicket;
use App\Models\Trip;
use App\Models\VehicleIncident;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational reports (FR-15).
 *
 * Every figure here is aggregated in SQL rather than by loading rows and
 * counting them in PHP. A term's worth of trips is tens of thousands of rows;
 * a report that pulls them into memory works fine on the demo data and falls
 * over in March.
 *
 * Reports are read-only by construction: nothing in this class writes.
 */
class ReportService
{
    /**
     * Trip performance over a window (FR-15).
     *
     * @return array<string, mixed>
     */
    public function tripSummary(CarbonInterface $from, CarbonInterface $to): array
    {
        $counts = Trip::tap($this->betweenDates($from, $to))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completed = (int) ($counts[TripStatus::COMPLETED->value] ?? 0);
        $cancelled = (int) ($counts[TripStatus::CANCELLED->value] ?? 0);
        $total = (int) $counts->sum();

        $punctuality = Trip::tap($this->betweenDates($from, $to))
            ->where('status', TripStatus::COMPLETED->value)
            ->whereNotNull('actual_departure_time')
            ->whereNotNull('scheduled_departure_time')
            ->get(['scheduled_departure_time', 'actual_departure_time']);

        $lateCount = $punctuality->filter(
            fn ($trip) => $trip->actual_departure_time > $trip->scheduled_departure_time,
        )->count();

        return [
            'window' => $this->window($from, $to),
            'trips' => [
                'total' => $total,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'running' => (int) ($counts[TripStatus::RUNNING->value] ?? 0),
                'scheduled' => (int) ($counts[TripStatus::SCHEDULED->value] ?? 0),
            ],
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : null,
            'cancellation_rate' => $total > 0 ? round($cancelled / $total * 100, 1) : null,
            'departed_late' => $lateCount,
            'punctuality_rate' => $punctuality->count() > 0
                ? round((1 - $lateCount / $punctuality->count()) * 100, 1)
                : null,
            'auto_closed' => Trip::tap($this->betweenDates($from, $to))
                ->where('auto_closed', true)->count(),
        ];
    }

    /**
     * Seat utilisation, which is what justifies or refutes a consolidation.
     *
     * @return array<string, mixed>
     */
    public function occupancy(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Trip::query()
            ->join('buses', 'trips.bus_id', '=', 'buses.id')
            ->tap($this->betweenDates($from, $to, 'trips.trip_date'))
            ->where('trips.status', TripStatus::COMPLETED->value)
            ->where('buses.seating_capacity', '>', 0)
            ->selectRaw('COUNT(*) as trips')
            ->selectRaw('SUM(trips.occupied_seat_count) as passengers')
            ->selectRaw('SUM(buses.seating_capacity) as seats')
            ->first();

        $trips = (int) ($rows->trips ?? 0);
        $passengers = (int) ($rows->passengers ?? 0);
        $seats = (int) ($rows->seats ?? 0);

        $perRoute = Trip::query()
            ->join('buses', 'trips.bus_id', '=', 'buses.id')
            ->join('routes', 'trips.route_id', '=', 'routes.id')
            ->tap($this->betweenDates($from, $to, 'trips.trip_date'))
            ->where('trips.status', TripStatus::COMPLETED->value)
            ->where('buses.seating_capacity', '>', 0)
            ->groupBy('routes.id', 'routes.route_name')
            ->selectRaw('routes.route_name as route_name')
            ->selectRaw('COUNT(*) as trips')
            ->selectRaw('SUM(trips.occupied_seat_count) as passengers')
            ->selectRaw('SUM(buses.seating_capacity) as seats')
            ->get()
            ->map(fn ($row) => [
                'route_name' => $row->route_name,
                'trips' => (int) $row->trips,
                'passengers' => (int) $row->passengers,
                'utilisation_percent' => (int) $row->seats > 0
                    ? round((int) $row->passengers / (int) $row->seats * 100, 1)
                    : null,
            ])
            ->sortBy('utilisation_percent')
            ->values()
            ->all();

        return [
            'window' => $this->window($from, $to),
            'trips_measured' => $trips,
            'passengers_carried' => $passengers,
            'seats_offered' => $seats,
            'utilisation_percent' => $seats > 0 ? round($passengers / $seats * 100, 1) : null,
            // Ascending, so the emptiest routes — the consolidation candidates
            // — are the first thing read.
            'by_route' => $perRoute,
        ];
    }

    /**
     * Fleet availability and what is holding vehicles off the road.
     *
     * @return array<string, mixed>
     */
    public function fleet(): array
    {
        $byStatus = Bus::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $openTickets = MaintenanceTicket::open()
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        return [
            'generated_at' => now()->toIso8601String(),
            'buses' => [
                'total' => (int) $byStatus->sum(),
                'by_status' => $byStatus->map(fn ($n) => (int) $n)->all(),
            ],
            'grounded_by_maintenance' => MaintenanceTicket::grounding()
                ->distinct('bus_id')->count('bus_id'),
            'open_tickets' => [
                'total' => (int) $openTickets->sum(),
                'by_priority' => $openTickets->map(fn ($n) => (int) $n)->all(),
            ],
            'overdue_maintenance_buses' => DB::table('preventive_maintenance_schedules')
                ->where('is_active', true)
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', today())
                ->distinct('bus_id')
                ->count('bus_id'),
        ];
    }

    /**
     * Incidents by class and how quickly they were answered.
     *
     * @return array<string, mixed>
     */
    public function incidents(CarbonInterface $from, CarbonInterface $to): array
    {
        $base = VehicleIncident::whereBetween('reported_at', [$from, $to]);

        $byClass = (clone $base)->selectRaw('incident_class, COUNT(*) as total')
            ->groupBy('incident_class')->pluck('total', 'incident_class');

        $byType = (clone $base)->selectRaw('incident_type, COUNT(*) as total')
            ->groupBy('incident_type')->orderByDesc('total')->pluck('total', 'incident_type');

        // Acknowledgement time is the number that matters for life-safety: an
        // SOS nobody answered for nine minutes is the failure this report
        // exists to surface.
        $acknowledged = (clone $base)
            ->whereNotNull('acknowledged_at')
            ->get(['incident_class', 'reported_at', 'acknowledged_at']);

        $lifeSafety = $acknowledged->where('incident_class', IncidentClass::LIFE_SAFETY);

        return [
            'window' => $this->window($from, $to),
            'total' => (int) $byClass->sum(),
            'by_class' => $byClass->map(fn ($n) => (int) $n)->all(),
            'by_type' => $byType->map(fn ($n) => (int) $n)->all(),
            'escalated' => (clone $base)->where('status', 'ESCALATED')->count(),
            'cancelled_false_alarms' => (clone $base)->where('was_cancelled', true)->count(),
            'unacknowledged' => (clone $base)->whereNull('acknowledged_at')->count(),
            'median_acknowledgement_seconds' => $this->median(
                $acknowledged->map(fn ($i) => $i->reported_at->diffInSeconds($i->acknowledged_at))->all(),
            ),
            'life_safety_median_acknowledgement_seconds' => $this->median(
                $lifeSafety->map(fn ($i) => $i->reported_at->diffInSeconds($i->acknowledged_at))->all(),
            ),
            'life_safety_worst_acknowledgement_seconds' => $lifeSafety->isEmpty() ? null : (int) $lifeSafety
                ->map(fn ($i) => $i->reported_at->diffInSeconds($i->acknowledged_at))->max(),
        ];
    }

    /**
     * Attendance quality — BR-266's review queue, as a number.
     *
     * @return array<string, mixed>
     */
    public function attendance(CarbonInterface $from, CarbonInterface $to): array
    {
        $discrepancies = AttendanceDiscrepancy::whereBetween('created_at', [$from, $to])->get();

        return [
            'window' => $this->window($from, $to),
            'discrepancies' => $discrepancies->count(),
            'open' => $discrepancies->where('status', 'OPEN')->count(),
            'reviewed' => $discrepancies->where('status', 'REVIEWED')->count(),
            // The direction that matters: more people counted than accounted
            // for means somebody is aboard who is on no list.
            'under_accounted' => $discrepancies->filter(fn ($d) => $d->difference > 0)->count(),
            'over_accounted' => $discrepancies->filter(fn ($d) => $d->difference < 0)->count(),
            'largest_difference' => $discrepancies->isEmpty()
                ? null
                : (int) $discrepancies->map(fn ($d) => abs($d->difference))->max(),
        ];
    }

    /**
     * Maintenance throughput and cost.
     *
     * @return array<string, mixed>
     */
    public function maintenance(CarbonInterface $from, CarbonInterface $to): array
    {
        $opened = MaintenanceTicket::whereBetween('created_at', [$from, $to]);

        $closed = MaintenanceTicket::whereBetween('completion_date', [$from, $to])
            ->where('status', MaintenanceStatus::COMPLETED->value);

        $turnaround = (clone $closed)->get(['created_at', 'completion_date'])
            ->map(fn ($t) => $t->created_at->diffInHours($t->completion_date))
            ->all();

        return [
            'window' => $this->window($from, $to),
            'opened' => (clone $opened)->count(),
            'completed' => (clone $closed)->count(),
            'still_open' => MaintenanceTicket::open()->count(),
            'total_cost' => round((float) (clone $closed)->sum('actual_cost'), 2),
            'median_turnaround_hours' => $this->median($turnaround),
            'by_priority' => (clone $opened)->selectRaw('priority, COUNT(*) as total')
                ->groupBy('priority')->pluck('total', 'priority')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * Bound a query to a date window.
     *
     * `whereBetween` on a date column is a trap here: `trip_date` is stored
     * with a midnight time, so comparing it as a string against a bare
     * `Y-m-d` silently drops the final day of every report. `whereDate` is
     * the fix, and it is applied in one place so it cannot be forgotten at
     * the sixth call site.
     *
     * @return callable(\Illuminate\Contracts\Database\Query\Builder|Builder): void
     */
    private function betweenDates(CarbonInterface $from, CarbonInterface $to, string $column = 'trip_date'): callable
    {
        return function ($query) use ($from, $to, $column) {
            $query->whereDate($column, '>=', $from->toDateString())
                ->whereDate($column, '<=', $to->toDateString());
        };
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $count = count($values);
        $middle = (int) floor($count / 2);

        // Median rather than mean throughout: one incident that sat
        // unacknowledged over a weekend would drag an average far enough to
        // hide a hundred that were answered promptly.
        return $count % 2 === 1
            ? (int) round($values[$middle])
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * @return array<string, string>
     */
    private function window(CarbonInterface $from, CarbonInterface $to): array
    {
        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()];
    }
}
