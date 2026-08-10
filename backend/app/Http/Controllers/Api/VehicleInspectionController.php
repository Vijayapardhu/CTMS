<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccessLevel;
use App\Enums\InspectionItem;
use App\Enums\InspectionOutcome;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\StoreVehicleInspectionRequest;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\VehicleInspection;
use App\Services\Fleet\VehicleInspectionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pre-trip vehicle inspections (FR-02, DR-03, AD-22).
 */
class VehicleInspectionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VehicleInspectionService $inspections) {}

    /**
     * GET /api/v1/inspections/checklist
     *
     * The checklist the driver app renders. Served rather than hard-coded in
     * the client so adding an item does not require an app release.
     */
    public function checklist(): JsonResponse
    {
        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value,
            'label' => $item->label(),
            'safety_critical' => $item->isSafetyCritical(),
        ], InspectionItem::cases());

        return $this->success($items, 'Inspection checklist retrieved successfully.');
    }

    /**
     * GET /api/v1/buses/{id}/service-readiness
     *
     * Whether the bus may start a trip today, and why not if it may not.
     * Drives the disabled "Start trip" state in the driver app (DR-01).
     */
    public function readiness(string $busId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $this->authorize('view', $bus);

        $readiness = $this->inspections->serviceReadiness($bus);

        return $this->success([
            'cleared' => $readiness['cleared'],
            'reasons' => $readiness['reasons'],
            'inspection' => $readiness['inspection']?->load('items'),
        ], $readiness['cleared']
            ? 'This bus is cleared for service.'
            : 'This bus is not cleared for service.');
    }

    /**
     * POST /api/v1/buses/{id}/inspections
     */
    public function store(StoreVehicleInspectionRequest $request, string $busId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $driver = $this->resolveInspectingDriver($request, $bus);

        $inspection = $this->inspections->submit(
            bus: $bus,
            driver: $driver,
            items: $request->validated('items'),
            odometerReading: (int) $request->validated('odometer_reading'),
            actor: $request->user(),
            notes: $request->validated('notes'),
        );

        return $this->created($inspection, match ($inspection->outcome) {
            InspectionOutcome::PASSED => 'Inspection passed. This bus is cleared for service.',
            InspectionOutcome::PASSED_WITH_DEFECTS => 'Inspection recorded. A maintenance ticket has been opened for the defects found; the bus may still run.',
            InspectionOutcome::FAILED => 'Inspection failed. This bus has been taken out of service and a maintenance ticket has been opened.',
        });
    }

    /**
     * GET /api/v1/buses/{id}/inspections
     */
    public function index(Request $request, string $busId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $this->authorize('view', $bus);

        $filters = $request->validate([
            'outcome' => ['sometimes', Rule::enum(InspectionOutcome::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $bus->inspections()->with(['items', 'driver.user']);

        if (isset($filters['outcome'])) {
            $query->where('outcome', strtoupper($filters['outcome']));
        }

        $inspections = $query->latest('inspected_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($inspections, 'Inspections retrieved successfully.');
    }

    /**
     * GET /api/v1/inspections/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $inspection = VehicleInspection::with(['items', 'bus', 'driver.user', 'maintenanceTicket'])->find($id);

        if (! $inspection) {
            throw new ResourceNotFoundException('Inspection not found.');
        }

        $user = $request->user();

        // Admins see any; a driver sees only their own submissions.
        if (! $user->isAdmin() && $inspection->driver?->user_id !== $user->getKey()) {
            throw new AuthorizationException('You do not have permission to view this inspection.');
        }

        return $this->success($inspection, 'Inspection retrieved successfully.');
    }

    /**
     * A driver inspects on their own behalf. An administrator may record one
     * for a named driver — a depot supervisor entering a paper checklist.
     */
    private function resolveInspectingDriver(Request $request, Bus $bus): Driver
    {
        $user = $request->user();

        if ($user->isDriver()) {
            $driver = $user->driver;

            if (! $driver) {
                throw new AuthorizationException('Your driver profile is not set up.');
            }

            return $driver;
        }

        // Recording an inspection clears a bus for service or takes it off the
        // road. Standing in for a driver here carries the same weight as
        // returning a vehicle to service, so it asks for the same tier.
        if (! $user->hasAccessLevel(AccessLevel::OPERATIONS)) {
            throw new AuthorizationException('Only drivers and transport operations can submit an inspection.');
        }

        $validated = $request->validate([
            'driver_id' => ['required', 'uuid', 'exists:drivers,id'],
        ]);

        return Driver::findOrFail($validated['driver_id']);
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
