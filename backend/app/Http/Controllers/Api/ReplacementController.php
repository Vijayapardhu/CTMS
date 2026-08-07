<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReplacementStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\ReplacementAssignment;
use App\Services\Incidents\ReplacementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Replacement vehicles (FR-12, AD-75).
 *
 * Every action here is operations-only: dispatching a replacement costs money
 * and pulls a vehicle off another duty.
 */
class ReplacementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReplacementService $replacements) {}

    /**
     * GET /api/v1/replacements
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(ReplacementStatus::class)],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ReplacementAssignment::with([
            'trip.route', 'originalBus', 'replacementBus', 'incident',
        ]);

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        $assignments = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($assignments, 'Replacement requests retrieved successfully.');
    }

    /**
     * GET /api/v1/replacements/{id}
     */
    public function show(string $id): JsonResponse
    {
        $assignment = $this->find($id);

        $assignment->load(['trip.route', 'originalBus', 'replacementBus', 'incident', 'approvedBy']);

        return $this->success($assignment, 'Replacement request retrieved successfully.');
    }

    /**
     * POST /api/v1/replacements/{id}/approve
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $assignment = $this->find($id);

        $validated = $request->validate([
            'bus_id' => ['nullable', 'uuid', 'exists:buses,id'],
            'driver_id' => ['nullable', 'uuid', 'exists:drivers,id'],
        ]);

        $assignment = $this->replacements->approve(
            $assignment,
            $request->user(),
            isset($validated['bus_id']) ? Bus::find($validated['bus_id']) : null,
            isset($validated['driver_id']) ? Driver::find($validated['driver_id']) : null,
        );

        return $this->success($assignment, 'Replacement approved. Dispatch it when the driver is on the way.');
    }

    /**
     * POST /api/v1/replacements/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $assignment = $this->find($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->success(
            $this->replacements->reject($assignment, $validated['reason'], $request->user()),
            'Replacement request rejected.',
        );
    }

    /**
     * POST /api/v1/replacements/{id}/dispatch
     */
    public function dispatchReplacement(Request $request, string $id): JsonResponse
    {
        $assignment = $this->find($id);

        return $this->success(
            $this->replacements->dispatch($assignment, $request->user()),
            'Replacement dispatched. Affected passengers have been told what to look for.',
        );
    }

    /**
     * POST /api/v1/replacements/{id}/arrived
     */
    public function markArrived(Request $request, string $id): JsonResponse
    {
        $assignment = $this->find($id);

        return $this->success(
            $this->replacements->markArrived($assignment, $request->user()),
            'Replacement on scene. The trip has moved onto the new vehicle.',
        );
    }

    private function find(string $id): ReplacementAssignment
    {
        $assignment = ReplacementAssignment::find($id);

        if (! $assignment) {
            throw new ResourceNotFoundException('Replacement request not found.');
        }

        return $assignment;
    }
}
