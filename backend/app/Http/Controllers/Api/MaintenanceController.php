<?php

namespace App\Http\Controllers\Api;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Maintenance\AssignTicketRequest;
use App\Http\Requests\Maintenance\CancelTicketRequest;
use App\Http\Requests\Maintenance\CompleteTicketRequest;
use App\Http\Requests\Maintenance\ScheduleTicketRequest;
use App\Http\Requests\Maintenance\StoreTicketRequest;
use App\Models\Bus;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Services\Maintenance\MaintenanceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Maintenance tickets (FR-14).
 */
class MaintenanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MaintenanceService $maintenance) {}

    /**
     * GET /api/v1/maintenance-tickets
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaintenanceTicket::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(MaintenanceStatus::class)],
            'priority' => ['sometimes', Rule::enum(MaintenancePriority::class)],
            'bus_id' => ['sometimes', 'uuid', 'exists:buses,id'],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MaintenanceTicket::with(['bus', 'assignedTo', 'openedBy', 'completedBy']);

        $actor = $request->user();

        // A driver sees only the jobs on the bus they are assigned to.
        if ($actor->isDriver()) {
            $query->where('bus_id', $actor->driver?->assigned_bus_id);
        }

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if (isset($filters['priority'])) {
            $query->where('priority', strtoupper($filters['priority']));
        }

        if (isset($filters['bus_id'])) {
            $query->where('bus_id', $filters['bus_id']);
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        return $this->paginated(
            $query->byUrgency()->paginate($this->perPage($filters['per_page'] ?? null)),
            'Maintenance tickets retrieved successfully.',
        );
    }

    /**
     * GET /api/v1/maintenance-tickets/{id}
     */
    public function show(string $id): JsonResponse
    {
        $ticket = $this->find($id);

        $this->authorize('view', $ticket);

        $ticket->load(['bus', 'assignedTo', 'openedBy', 'completedBy', 'incident', 'inspection']);

        return $this->success($ticket, 'Maintenance ticket retrieved successfully.');
    }

    /**
     * POST /api/v1/maintenance-tickets
     */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $this->authorize('create', MaintenanceTicket::class);

        $validated = $request->validated();

        $ticket = $this->maintenance->open(
            Bus::findOrFail($validated['bus_id']),
            $validated,
            $request->user(),
        );

        return $this->created($ticket, 'Maintenance ticket opened.');
    }

    /**
     * POST /api/v1/maintenance-tickets/{id}/assign
     */
    public function assign(AssignTicketRequest $request, string $id): JsonResponse
    {
        $ticket = $this->find($id);

        $this->authorize('manage', $ticket);

        return $this->success(
            $this->maintenance->assign(
                $ticket,
                User::findOrFail($request->validated()['assigned_to_id']),
                $request->user(),
            ),
            'Maintenance ticket assigned.',
        );
    }

    /**
     * POST /api/v1/maintenance-tickets/{id}/schedule
     */
    public function schedule(ScheduleTicketRequest $request, string $id): JsonResponse
    {
        $ticket = $this->find($id);

        $this->authorize('manage', $ticket);

        return $this->success(
            $this->maintenance->schedule(
                $ticket,
                new \DateTimeImmutable($request->validated()['scheduled_date']),
                $request->user(),
            ),
            'Maintenance scheduled.',
        );
    }

    /**
     * POST /api/v1/maintenance-tickets/{id}/start
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $ticket = $this->find($id);

        $this->authorize('manage', $ticket);

        return $this->success(
            $this->maintenance->start($ticket, $request->user()),
            'Work started on this ticket.',
        );
    }

    /**
     * POST /api/v1/maintenance-tickets/{id}/complete
     *
     * BR-358 — this is the act that can return a vehicle to the road.
     */
    public function complete(CompleteTicketRequest $request, string $id): JsonResponse
    {
        $ticket = $this->find($id);

        $this->authorize('complete', $ticket);

        $ticket = $this->maintenance->complete($ticket, $request->validated(), $request->user());

        $message = $ticket->bus?->isAvailable()
            ? 'Ticket signed off. Nothing else is holding this bus, so it is back in service.'
            : 'Ticket signed off. The bus stays off the road until its remaining work is cleared.';

        return $this->success($ticket, $message);
    }

    /**
     * POST /api/v1/maintenance-tickets/{id}/cancel
     */
    public function cancel(CancelTicketRequest $request, string $id): JsonResponse
    {
        $ticket = $this->find($id);

        $this->authorize('manage', $ticket);

        return $this->success(
            $this->maintenance->cancel($ticket, $request->validated()['reason'], $request->user()),
            'Maintenance ticket cancelled.',
        );
    }

    private function find(string $id): MaintenanceTicket
    {
        $ticket = MaintenanceTicket::find($id);

        if (! $ticket) {
            throw new ResourceNotFoundException('Maintenance ticket not found.');
        }

        return $ticket;
    }
}
