<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trip\CancelTripRequest;
use App\Http\Requests\Trip\ReassignTripRequest;
use App\Http\Requests\Trip\StoreTripRequest;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Trip;
use App\Services\Trips\TripGenerationService;
use App\Services\Trips\TripService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Trip lifecycle (FR-06).
 *
 * This controller orchestrates trips and nothing else. Notifications are the
 * platform's business, reached only through domain events published by
 * TripService.
 */
class TripController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TripService $trips,
        private readonly TripGenerationService $generator,
    ) {}

    /**
     * GET /api/v1/trips
     *
     * Scoped by role: a driver sees their own duty, a student sees the route
     * they ride, staff see everything.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Trip::class);

        $filters = $request->validate([
            'date' => ['sometimes', 'date'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'status' => ['sometimes', Rule::enum(TripStatus::class)],
            'route_id' => ['sometimes', 'uuid'],
            'bus_id' => ['sometimes', 'uuid'],
            'driver_id' => ['sometimes', 'uuid'],
            'anomalous' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Trip::with(['route', 'bus', 'driver.user']);

        $this->scopeToActor($query, $request);

        if (isset($filters['date'])) {
            $query->whereDate('trip_date', $filters['date']);
        } elseif (isset($filters['from']) || isset($filters['to'])) {
            if (isset($filters['from'])) {
                $query->whereDate('trip_date', '>=', $filters['from']);
            }
            if (isset($filters['to'])) {
                $query->whereDate('trip_date', '<=', $filters['to']);
            }
        } else {
            // Today by default — the working view for everyone.
            $query->whereDate('trip_date', now()->toDateString());
        }

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        foreach (['route_id', 'bus_id', 'driver_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        // Trips that closed abnormally, as a filterable group (BR-261).
        if ($request->boolean('anomalous')) {
            $query->where('auto_closed', true);
        }

        $trips = $query->orderBy('trip_date')
            ->orderBy('scheduled_departure_time')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($trips, 'Trips retrieved successfully.');
    }

    /**
     * GET /api/v1/trips/{id}
     */
    public function show(string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('view', $trip);

        $trip->load(['route.stops', 'bus', 'driver.user', 'startedBy', 'endedBy', 'cancelledBy']);

        return $this->success($trip, 'Trip retrieved successfully.');
    }

    /**
     * POST /api/v1/trips — an ad-hoc trip outside the timetable.
     */
    public function store(StoreTripRequest $request): JsonResponse
    {
        $this->authorize('create', Trip::class);

        $trip = $this->trips->createAdHoc(
            $request->validated(),
            $request->user(),
            $request->validated('override_reason'),
        );

        return $this->created($trip, 'Trip created successfully.');
    }

    /**
     * POST /api/v1/trips/{id}/start
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $trip = $this->trips->start($trip, $request->user());

        return $this->success($trip, 'Trip started. Passengers have been notified.');
    }

    /**
     * POST /api/v1/trips/{id}/complete
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $trip = $this->trips->complete($trip, $request->user(), $validated['notes'] ?? null);

        return $this->success($trip, 'Trip completed.');
    }

    /**
     * POST /api/v1/trips/{id}/cancel
     */
    public function cancel(CancelTripRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('cancel', $trip);

        $trip = $this->trips->cancel($trip, $request->validated('reason'), $request->user());

        return $this->success($trip, 'Trip cancelled. Passengers have been notified.');
    }

    /**
     * POST /api/v1/trips/{id}/reassign
     */
    public function reassign(ReassignTripRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('reassign', $trip);

        $busId = $request->validated('bus_id');
        $driverId = $request->validated('driver_id');

        $trip = $this->trips->reassign(
            $trip,
            $busId ? Bus::find($busId) : null,
            $driverId ? Driver::find($driverId) : null,
            $request->user(),
            $request->validated('reason'),
        );

        return $this->success($trip, 'Trip reassigned successfully.');
    }

    /**
     * POST /api/v1/trips/generate — run generation for a date (AD-66).
     *
     * Idempotent, so re-running after fixing an exception is the documented
     * recovery rather than a risk.
     */
    public function generate(Request $request): JsonResponse
    {
        $this->authorize('create', Trip::class);

        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $result = $this->generator->generateFor(
            Carbon::parse($validated['date']),
            $request->user(),
        );

        return $this->success($result, $result['suspended']
            ? "No trips generated — {$result['reason']}."
            : "{$result['created']} trip(s) generated, {$result['skipped']} already existed.");
    }

    /**
     * Narrow the query to what this caller is entitled to see.
     */
    private function scopeToActor($query, Request $request): void
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->isDriver()) {
            $query->where('driver_id', $user->driver?->getKey());

            return;
        }

        if ($user->isStudent()) {
            // A student with no transport assignment sees nothing, rather
            // than every trip in the fleet.
            $query->where('route_id', $user->student?->route_id ?? '');

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function findTrip(string $id): Trip
    {
        $trip = Trip::find($id);

        if (! $trip) {
            throw new ResourceNotFoundException('Trip not found.');
        }

        return $trip;
    }
}
