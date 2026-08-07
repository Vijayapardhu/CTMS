<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\AssignBusRequest;
use App\Http\Requests\Driver\StoreDriverRequest;
use App\Http\Requests\Driver\UpdateDriverRequest;
use App\Http\Requests\Driver\UpdateDriverStatusRequest;
use App\Models\Bus;
use App\Models\Driver;
use App\Services\Fleet\DriverService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Driver management (FR-03).
 */
class DriverController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DriverService $drivers) {}

    /**
     * GET /api/v1/drivers — administrators only.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Driver::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(DriverStatus::class)],
            'assignable' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Driver::with(['user', 'assignedBus']);

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if ($request->boolean('assignable')) {
            $query->assignable();
        }

        if (isset($filters['search'])) {
            $search = addcslashes($filters['search'], '%_\\');

            $query->where(function ($q) use ($search) {
                $q->where('license_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $drivers = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($drivers, 'Drivers retrieved successfully.');
    }

    /**
     * POST /api/v1/drivers
     */
    public function store(StoreDriverRequest $request): JsonResponse
    {
        $this->authorize('create', Driver::class);

        $driver = $this->drivers->create($request->validated(), $request->user());

        return $this->created($driver, 'Driver profile created successfully.');
    }

    /**
     * GET /api/v1/drivers/{id} — administrators, or the driver themselves.
     */
    public function show(string $id): JsonResponse
    {
        $driver = $this->findDriver($id);

        $this->authorize('view', $driver);

        $driver->load(['user', 'assignedBus']);

        return $this->success($driver, 'Driver retrieved successfully.');
    }

    /**
     * PUT /api/v1/drivers/{id}
     */
    public function update(UpdateDriverRequest $request, string $id): JsonResponse
    {
        $driver = $this->findDriver($id);

        $this->authorize('update', $driver);

        $driver = $this->drivers->update($driver, $request->validated(), $request->user());

        return $this->success($driver, 'Driver updated successfully.');
    }

    /**
     * PATCH /api/v1/drivers/{id}/status
     */
    public function updateStatus(UpdateDriverStatusRequest $request, string $id): JsonResponse
    {
        $driver = $this->findDriver($id);

        $this->authorize('changeStatus', $driver);

        $driver = $this->drivers->changeStatus($driver, $request->status(), $request->user());

        return $this->success($driver, "Driver status updated to {$driver->status->value}.");
    }

    /**
     * POST /api/v1/drivers/{id}/assign-bus
     */
    public function assignBus(AssignBusRequest $request, string $id): JsonResponse
    {
        $driver = $this->findDriver($id);

        $this->authorize('assignBus', $driver);

        $bus = Bus::find($request->validated('bus_id'));

        if (! $bus) {
            throw new ResourceNotFoundException('Bus not found.');
        }

        $driver = $this->drivers->assignBus($driver, $bus, $request->user());

        return $this->success($driver, 'Bus assigned to driver successfully.');
    }

    /**
     * DELETE /api/v1/drivers/{id}/assign-bus
     */
    public function unassignBus(Request $request, string $id): JsonResponse
    {
        $driver = $this->findDriver($id);

        $this->authorize('assignBus', $driver);

        $driver = $this->drivers->unassignBus($driver, $request->user());

        return $this->success($driver, 'Bus released from driver successfully.');
    }

    /**
     * DELETE /api/v1/drivers/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $driver = $this->findDriver($id);

        $this->authorize('delete', $driver);

        $this->drivers->retire($driver, $request->user());

        return $this->success(null, 'Driver removed successfully.');
    }

    private function findDriver(string $id): Driver
    {
        $driver = Driver::find($id);

        if (! $driver) {
            throw new ResourceNotFoundException('Driver not found.');
        }

        return $driver;
    }
}
