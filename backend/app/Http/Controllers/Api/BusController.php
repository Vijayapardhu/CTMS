<?php

namespace App\Http\Controllers\Api;

use App\Enums\BusStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\StoreBusRequest;
use App\Http\Requests\Bus\UpdateBusRequest;
use App\Http\Requests\Bus\UpdateBusStatusRequest;
use App\Models\Bus;
use App\Services\Fleet\BusService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bus management (FR-02).
 */
class BusController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusService $buses) {}

    /**
     * GET /api/v1/buses
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bus::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(BusStatus::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'min_capacity' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Bus::query();

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if (isset($filters['min_capacity'])) {
            $query->where('seating_capacity', '>=', $filters['min_capacity']);
        }

        if (isset($filters['search'])) {
            // Escape the LIKE wildcards so a search for "%" does not match
            // every row in the table.
            $search = addcslashes($filters['search'], '%_\\');

            $query->where(function ($q) use ($search) {
                $q->where('registration_number', 'like', "%{$search}%")
                    ->orWhere('vehicle_name', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $buses = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($buses, 'Buses retrieved successfully.');
    }

    /**
     * POST /api/v1/buses
     */
    public function store(StoreBusRequest $request): JsonResponse
    {
        $this->authorize('create', Bus::class);

        $bus = $this->buses->create($request->validated(), $request->user());

        return $this->created($bus, 'Bus created successfully.');
    }

    /**
     * GET /api/v1/buses/{id}
     */
    public function show(string $id): JsonResponse
    {
        $bus = $this->findBus($id);

        $this->authorize('view', $bus);

        $bus->load(['schedules', 'incidents', 'maintenanceTickets']);

        return $this->success($bus, 'Bus retrieved successfully.');
    }

    /**
     * PUT /api/v1/buses/{id}
     */
    public function update(UpdateBusRequest $request, string $id): JsonResponse
    {
        $bus = $this->findBus($id);

        $this->authorize('update', $bus);

        $bus = $this->buses->update($bus, $request->validated(), $request->user());

        return $this->success($bus, 'Bus updated successfully.');
    }

    /**
     * PATCH /api/v1/buses/{id}/status
     */
    public function updateStatus(UpdateBusStatusRequest $request, string $id): JsonResponse
    {
        $bus = $this->findBus($id);

        $this->authorize('changeStatus', $bus);

        $bus = $this->buses->changeStatus(
            $bus,
            $request->status(),
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success($bus, "Bus status updated to {$bus->status->value}.");
    }

    /**
     * DELETE /api/v1/buses/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $bus = $this->findBus($id);

        $this->authorize('delete', $bus);

        $this->buses->retire($bus, $request->user());

        return $this->success(null, 'Bus removed from the fleet.');
    }

    private function findBus(string $id): Bus
    {
        $bus = Bus::find($id);

        if (! $bus) {
            throw new ResourceNotFoundException('Bus not found.');
        }

        return $bus;
    }
}
