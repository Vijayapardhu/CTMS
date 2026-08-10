<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentClass;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incident\ReportIncidentRequest;
use App\Models\Trip;
use App\Models\VehicleIncident;
use App\Services\Incidents\IncidentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Incident reporting and triage (FR-11).
 */
class IncidentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly IncidentService $incidents) {}

    /**
     * GET /api/v1/incidents/types
     *
     * The reporting form the driver app renders, served rather than compiled
     * into the client so a new incident type does not need an app release.
     */
    public function types(): JsonResponse
    {
        $types = array_map(fn (IncidentType $type) => [
            'type' => $type->value,
            'label' => $type->label(),
            'class' => $type->class()->value,
            'class_label' => $type->class()->label(),
            'default_severity' => $type->defaultSeverity()->value,
            'requires_photo' => $type->requiresPhoto(),
        ], IncidentType::reportableCases());

        return $this->success($types, 'Incident types retrieved successfully.');
    }

    /**
     * POST /api/v1/incidents
     */
    public function store(ReportIncidentRequest $request): JsonResponse
    {
        $this->authorize('create', VehicleIncident::class);

        $trip = $request->validated('trip_id')
            ? Trip::with('bus')->find($request->validated('trip_id'))
            : null;

        $incident = $this->incidents->report($request->validated(), $request->user(), $trip);

        return $this->created($incident, $incident->isLifeSafety()
            ? 'Emergency reported. The transport office has been alerted on every channel.'
            : 'Incident reported. A maintenance ticket has been opened.');
    }

    /**
     * GET /api/v1/incidents
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VehicleIncident::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(IncidentStatus::class)],
            'class' => ['sometimes', Rule::enum(IncidentClass::class)],
            'type' => ['sometimes', Rule::enum(IncidentType::class)],
            'open' => ['sometimes', 'boolean'],
            'trip_id' => ['sometimes', 'uuid'],
            'bus_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = VehicleIncident::with(['bus', 'driver.user', 'trip.route', 'reportedBy']);

        // A driver sees what they reported; staff see everything.
        if (! $request->user()->isAdmin()) {
            $query->where('reported_by_id', $request->user()->getKey());
        }

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if (isset($filters['class'])) {
            $query->where('incident_class', strtoupper($filters['class']));
        }

        if (isset($filters['type'])) {
            $query->where('incident_type', strtoupper($filters['type']));
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        foreach (['trip_id', 'bus_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        // Life-safety first, then newest: an unresolved SOS must never be on
        // page two.
        $incidents = $query
            ->orderByRaw("CASE incident_class WHEN 'LIFE_SAFETY' THEN 0 WHEN 'OPERATIONAL' THEN 1 ELSE 2 END")
            ->latest('reported_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($incidents, 'Incidents retrieved successfully.');
    }

    /**
     * GET /api/v1/incidents/{id}
     */
    public function show(string $id): JsonResponse
    {
        $incident = $this->findIncident($id);

        $this->authorize('view', $incident);

        $incident->load(['bus', 'driver.user', 'trip.route', 'notes.author', 'maintenanceTicket', 'replacement']);

        return $this->success($incident, 'Incident retrieved successfully.');
    }

    /**
     * POST /api/v1/incidents/{id}/acknowledge
     */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $incident = $this->findIncident($id);

        $this->authorize('triage', $incident);

        return $this->success(
            $this->incidents->acknowledge($incident, $request->user()),
            'Incident acknowledged.',
        );
    }

    /**
     * POST /api/v1/incidents/{id}/resolve
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $incident = $this->findIncident($id);

        $this->authorize('triage', $incident);

        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return $this->success(
            $this->incidents->resolve($incident, $validated['resolution_notes'], $request->user()),
            'Incident resolved.',
        );
    }

    /**
     * POST /api/v1/incidents/{id}/close
     */
    public function close(Request $request, string $id): JsonResponse
    {
        $incident = $this->findIncident($id);

        $this->authorize('triage', $incident);

        return $this->success(
            $this->incidents->close($incident, $request->user()),
            'Incident closed.',
        );
    }

    /**
     * POST /api/v1/incidents/{id}/cancel
     *
     * A false alarm. Recorded, never erased (BR-355).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $incident = $this->findIncident($id);

        $this->authorize('cancel', $incident);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return $this->success(
            $this->incidents->cancel($incident, $validated['note'], $request->user()),
            'Recorded as a false alarm. The original report is retained.',
        );
    }

    /**
     * POST /api/v1/incidents/{id}/notes
     */
    public function addNote(Request $request, string $id): JsonResponse
    {
        $incident = $this->findIncident($id);

        // Its own ability: reading an incident and writing on its record are
        // different permissions (G3-3).
        $this->authorize('addNote', $incident);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return $this->created(
            $this->incidents->addNote($incident, $validated['note'], $request->user()),
            'Note added.',
        );
    }

    private function findIncident(string $id): VehicleIncident
    {
        $incident = VehicleIncident::find($id);

        if (! $incident) {
            throw new ResourceNotFoundException('Incident not found.');
        }

        return $incident;
    }
}
