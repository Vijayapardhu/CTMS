<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConsolidationStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Consolidation\ProposeConsolidationRequest;
use App\Http\Requests\Consolidation\RejectConsolidationRequest;
use App\Models\Trip;
use App\Models\TripConsolidation;
use App\Services\Trips\ConsolidationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart consolidation (FR-13).
 *
 * Propose → approve → notify → execute. Four steps rather than one because
 * each is a different decision by a different party, and collapsing them is
 * how passengers end up finding out their bus was cancelled after it was.
 */
class ConsolidationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConsolidationService $consolidations) {}

    /**
     * GET /api/v1/consolidations
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TripConsolidation::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(ConsolidationStatus::class)],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TripConsolidation::with([
            'sourceTrip.route', 'targetTrip.route', 'targetTrip.bus', 'decidedBy',
        ]);

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        return $this->paginated(
            $query->latest('created_at')->paginate($this->perPage($filters['per_page'] ?? null)),
            'Consolidation proposals retrieved successfully.',
        );
    }

    /**
     * GET /api/v1/consolidations/candidates
     *
     * What the hourly analysis would propose, on demand.
     */
    public function candidates(Request $request): JsonResponse
    {
        $this->authorize('create', TripConsolidation::class);

        $validated = $request->validate([
            'occupancy_threshold' => ['sometimes', 'numeric', 'min:0.05', 'max:1'],
        ]);

        $pairs = array_map(fn (array $pair) => [
            'source_trip_id' => (string) $pair['source']->getKey(),
            'source_route' => $pair['source']->route?->route_name,
            'source_passengers' => $pair['source']->occupied_seat_count,
            'target_trip_id' => (string) $pair['target']->getKey(),
            'target_route' => $pair['target']->route?->route_name,
            'target_passengers' => $pair['target']->occupied_seat_count,
            'target_capacity' => $pair['target']->bus?->seating_capacity,
        ], $this->consolidations->findCandidates($validated['occupancy_threshold'] ?? null));

        return $this->success($pairs, 'Consolidation candidates retrieved successfully.');
    }

    /**
     * GET /api/v1/consolidations/{id}
     */
    public function show(string $id): JsonResponse
    {
        $consolidation = $this->find($id);

        $this->authorize('view', $consolidation);

        $consolidation->load([
            'sourceTrip.route', 'targetTrip.route', 'targetTrip.bus',
            'divergenceStop', 'proposedBy', 'decidedBy',
        ]);

        return $this->success($consolidation, 'Consolidation proposal retrieved successfully.');
    }

    /**
     * POST /api/v1/consolidations
     */
    public function store(ProposeConsolidationRequest $request): JsonResponse
    {
        $this->authorize('create', TripConsolidation::class);

        $validated = $request->validated();

        $consolidation = $this->consolidations->propose(
            Trip::findOrFail($validated['source_trip_id']),
            Trip::findOrFail($validated['target_trip_id']),
            $request->user(),
            $validated['reason'] ?? null,
        );

        return $this->created($consolidation, 'Consolidation proposed. It needs a manager decision before anything changes.');
    }

    /**
     * POST /api/v1/consolidations/{id}/approve
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $consolidation = $this->find($id);

        $this->authorize('decide', $consolidation);

        return $this->success(
            $this->consolidations->approve($consolidation, $request->user()),
            'Consolidation approved. Notify the affected passengers before executing it.',
        );
    }

    /**
     * POST /api/v1/consolidations/{id}/reject
     */
    public function reject(RejectConsolidationRequest $request, string $id): JsonResponse
    {
        $consolidation = $this->find($id);

        $this->authorize('decide', $consolidation);

        return $this->success(
            $this->consolidations->reject($consolidation, $request->validated()['reason'], $request->user()),
            'Consolidation rejected.',
        );
    }

    /**
     * POST /api/v1/consolidations/{id}/notify
     *
     * BR-363 — a separate step, because the ordering is the rule.
     */
    public function notify(Request $request, string $id): JsonResponse
    {
        $consolidation = $this->find($id);

        $this->authorize('decide', $consolidation);

        return $this->success(
            $this->consolidations->notifyPassengers($consolidation, $request->user()),
            'Affected passengers have been told which bus to look for.',
        );
    }

    /**
     * POST /api/v1/consolidations/{id}/execute
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        $consolidation = $this->find($id);

        $this->authorize('decide', $consolidation);

        return $this->success(
            $this->consolidations->execute($consolidation, $request->user()),
            'Trips merged. The stood-down trip now points at the one carrying its passengers.',
        );
    }

    private function find(string $id): TripConsolidation
    {
        $consolidation = TripConsolidation::find($id);

        if (! $consolidation) {
            throw new ResourceNotFoundException('Consolidation proposal not found.');
        }

        return $consolidation;
    }
}
