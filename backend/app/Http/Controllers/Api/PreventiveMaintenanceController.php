<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Maintenance\StorePreventiveScheduleRequest;
use App\Models\Bus;
use App\Models\MaintenanceTicket;
use App\Models\PreventiveMaintenanceSchedule;
use App\Services\AuditLogger;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preventive maintenance schedules (BG-16, BR-366).
 */
class PreventiveMaintenanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/preventive-maintenance
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', MaintenanceTicket::class);

        $filters = $request->validate([
            'bus_id' => ['sometimes', 'uuid', 'exists:buses,id'],
            'due' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PreventiveMaintenanceSchedule::with(['bus', 'openTicket'])->active();

        if (isset($filters['bus_id'])) {
            $query->where('bus_id', $filters['bus_id']);
        }

        $schedules = $query->orderBy('due_on')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        if ($request->boolean('due')) {
            // Due-ness spans two axes and cannot be expressed as one WHERE, so
            // it is filtered after the fact on the current page.
            $schedules->setCollection(
                $schedules->getCollection()->filter(
                    fn (PreventiveMaintenanceSchedule $s) => $s->isDue(),
                )->values(),
            );
        }

        return $this->paginated($schedules, 'Preventive maintenance schedules retrieved successfully.');
    }

    /**
     * POST /api/v1/preventive-maintenance
     */
    public function store(StorePreventiveScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', MaintenanceTicket::class);

        $validated = $request->validated();
        $bus = Bus::findOrFail($validated['bus_id']);

        $schedule = new PreventiveMaintenanceSchedule;

        $schedule->forceFill([
            'bus_id' => $bus->getKey(),
            'service_name' => $validated['service_name'],
            'description' => $validated['description'] ?? null,
            'interval_days' => $validated['interval_days'] ?? null,
            'interval_km' => $validated['interval_km'] ?? null,
            'grace_days' => $validated['grace_days'] ?? 7,
            'last_serviced_on' => $validated['last_serviced_on'] ?? null,
            'last_serviced_odometer' => $validated['last_serviced_odometer'] ?? $bus->current_odometer,
            'due_on' => isset($validated['interval_days'])
                ? Carbon::parse($validated['last_serviced_on'] ?? today())
                    ->addDays((int) $validated['interval_days'])
                : null,
            'due_at_odometer' => isset($validated['interval_km'])
                ? (int) ($validated['last_serviced_odometer'] ?? $bus->current_odometer ?? 0)
                    + (int) $validated['interval_km']
                : null,
            'is_active' => true,
        ])->save();

        $this->audit->created($schedule, $request->user());

        return $this->created($schedule, 'Preventive maintenance schedule created.');
    }

    /**
     * DELETE /api/v1/preventive-maintenance/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorize('create', MaintenanceTicket::class);

        $schedule = PreventiveMaintenanceSchedule::find($id);

        if (! $schedule) {
            throw new ResourceNotFoundException('Preventive maintenance schedule not found.');
        }

        $schedule->forceFill(['is_active' => false])->save();
        $schedule->delete();

        $this->audit->deleted($schedule, $request->user());

        return $this->noContent();
    }
}
