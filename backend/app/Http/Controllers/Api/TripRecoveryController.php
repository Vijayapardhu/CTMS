<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trip\CorrectTripRequest;
use App\Http\Requests\Trip\ReviewDiscrepancyRequest;
use App\Models\AttendanceDiscrepancy;
use App\Models\Trip;
use App\Services\Trips\TripRecoveryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Putting a closed trip's record right (BR-258) and the attendance
 * disagreements that must never be quietly resolved (BR-266).
 */
class TripRecoveryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TripRecoveryService $recovery) {}

    /**
     * GET /api/v1/trips/{id}/corrections
     */
    public function corrections(string $tripId): JsonResponse
    {
        $trip = $this->findTrip($tripId);

        $this->authorize('view', $trip);

        return $this->success(
            $trip->corrections()->with('correctedBy')->latest('created_at')->get(),
            'Trip corrections retrieved successfully.',
        );
    }

    /**
     * POST /api/v1/trips/{id}/corrections
     */
    public function correct(CorrectTripRequest $request, string $tripId): JsonResponse
    {
        $trip = $this->findTrip($tripId);

        $this->authorize('correct', $trip);

        $validated = $request->validated();

        $correction = $this->recovery->correct(
            $trip,
            $validated['field'],
            $validated['value'] ?? null,
            $validated['reason'],
            $request->user(),
        );

        return $this->created($correction, 'Correction recorded. The original value is preserved alongside it.');
    }

    /**
     * GET /api/v1/attendance-discrepancies
     */
    public function discrepancies(Request $request): JsonResponse
    {
        $this->authorize('reviewAttendance', Trip::class);

        $filters = $request->validate([
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AttendanceDiscrepancy::with(['trip.route', 'trip.driver.user', 'reviewedBy']);

        if ($request->boolean('open')) {
            $query->open();
        }

        return $this->paginated(
            $query->latest('created_at')->paginate($this->perPage($filters['per_page'] ?? null)),
            'Attendance discrepancies retrieved successfully.',
        );
    }

    /**
     * POST /api/v1/attendance-discrepancies/{id}/review
     */
    public function review(ReviewDiscrepancyRequest $request, string $id): JsonResponse
    {
        $this->authorize('reviewAttendance', Trip::class);

        $discrepancy = AttendanceDiscrepancy::find($id);

        if (! $discrepancy) {
            throw new ResourceNotFoundException('Attendance discrepancy not found.');
        }

        return $this->success(
            $this->recovery->reviewDiscrepancy($discrepancy, $request->validated()['note'], $request->user()),
            'Discrepancy reviewed. Both original figures are unchanged.',
        );
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
