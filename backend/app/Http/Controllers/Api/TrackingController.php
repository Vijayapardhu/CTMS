<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tracking\PassengerCountRequest;
use App\Http\Requests\Tracking\RecordPositionRequest;
use App\Models\Student;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Services\Tracking\EtaService;
use App\Services\Tracking\GeofenceService;
use App\Services\Tracking\GpsIngestionService;
use App\Services\Tracking\PassengerCountService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live tracking (FR-07, FR-08, FR-09).
 */
class TrackingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GpsIngestionService $gps,
        private readonly GeofenceService $geofences,
        private readonly EtaService $etas,
        private readonly PassengerCountService $passengers,
    ) {}

    /**
     * POST /api/v1/trips/{id}/positions
     *
     * Every position runs the full pipeline; nothing bypasses it.
     */
    public function recordPosition(RecordPositionRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $location = $this->gps->ingest($trip, $request->validated(), $request->user());

        return $location === null
            ? $this->success(null, 'This position was already recorded.')
            : $this->success($location, 'Position recorded.');
    }

    /**
     * GET /api/v1/trips/{id}/live
     *
     * The state a map needs, with the honesty about staleness that BR-305
     * requires: a twenty-minute-old position is never presented as current.
     */
    public function live(Request $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('view', $trip);

        $stale = (int) config('ctms.gps.stale_after_seconds', 120);
        $ageSeconds = $trip->last_gps_update?->diffInSeconds(now());

        $progress = TripStopProgress::with('stop')
            ->where('trip_id', $trip->getKey())
            ->orderBy('sequence_number')
            ->get();

        return $this->success([
            'trip_id' => (string) $trip->getKey(),
            'status' => $trip->status->value,
            'position' => $trip->current_latitude === null ? null : [
                'latitude' => (float) $trip->current_latitude,
                'longitude' => (float) $trip->current_longitude,
                'recorded_at' => $trip->last_gps_update?->toIso8601String(),
                'age_seconds' => $ageSeconds,
                // The client must be unable to mistake stale for live.
                'is_stale' => $ageSeconds !== null && $ageSeconds > $stale,
            ],
            'occupancy' => [
                'occupied' => $trip->occupied_seat_count,
                'capacity' => $trip->bus?->seating_capacity,
            ],
            'delay_minutes' => $this->etas->projectedDelayMinutes($trip),
            'stops' => $progress->map(fn (TripStopProgress $row) => [
                'stop_id' => (string) $row->route_stop_id,
                'stop_name' => $row->stop?->stop_name,
                'sequence_number' => $row->sequence_number,
                'state' => $row->state->value,
                'eta_at' => $row->eta_at?->toIso8601String(),
                'arrived_at' => $row->arrived_at?->toIso8601String(),
            ])->all(),
        ], 'Live trip state retrieved successfully.');
    }

    /**
     * GET /api/v1/trips/{id}/eta
     *
     * The answer to "when does my bus get to my stop?".
     */
    public function eta(Request $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('view', $trip);

        $validated = $request->validate([
            'stop_id' => ['sometimes', 'uuid'],
        ]);

        // A student defaults to their own stop; nobody has to know its id.
        $stopId = $validated['stop_id']
            ?? $request->user()->student?->pickup_stop_id;

        if ($stopId === null) {
            return $this->error('No stop specified and you have no assigned pickup stop.', 422);
        }

        return $this->success($this->etas->forStop($trip, $stopId), 'Estimate retrieved successfully.');
    }

    /**
     * POST /api/v1/trips/{id}/stops/{stopId}/arrive
     *
     * Manual arrival — the fallback when GPS is unavailable (BR-306).
     */
    public function markArrived(Request $request, string $id, string $stopId): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $progress = $this->findProgress($trip, $stopId);

        return $this->success(
            $this->geofences->markArrived($trip, $progress),
            'Arrival recorded.',
        );
    }

    /**
     * POST /api/v1/trips/{id}/stops/{stopId}/skip
     */
    public function skipStop(Request $request, string $id, string $stopId): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $progress = $this->findProgress($trip, $stopId);

        return $this->success(
            $this->geofences->skip($trip, $progress, $validated['reason'], $request->user()),
            'Stop marked as skipped. The students waiting there have been told.',
        );
    }

    /**
     * GET /api/v1/trips/{id}/stops/{stopId}/manifest
     */
    public function manifest(Request $request, string $id, string $stopId): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        return $this->success(
            $this->passengers->manifestForStop($trip, $stopId),
            'Manifest retrieved successfully.',
        );
    }

    /**
     * POST /api/v1/trips/{id}/board
     */
    public function board(PassengerCountRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $trip = $this->passengers->board(
            $trip,
            $request->user(),
            $request->student(),
            $request->validated('route_stop_id'),
            $request->validated('idempotency_key'),
        );

        return $this->success([
            'occupied' => $trip->occupied_seat_count,
            'capacity' => $trip->bus?->seating_capacity,
        ], 'Boarding recorded.');
    }

    /**
     * POST /api/v1/trips/{id}/alight
     */
    public function alight(PassengerCountRequest $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $trip = $this->passengers->alight(
            $trip,
            $request->user(),
            $request->student(),
            $request->validated('route_stop_id'),
            $request->validated('idempotency_key'),
        );

        return $this->success([
            'occupied' => $trip->occupied_seat_count,
            'capacity' => $trip->bus?->seating_capacity,
        ], 'Alighting recorded.');
    }

    /**
     * POST /api/v1/trips/{id}/left-behind
     */
    public function leftBehind(Request $request, string $id): JsonResponse
    {
        $trip = $this->findTrip($id);

        $this->authorize('operate', $trip);

        $validated = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['uuid', 'exists:students,id'],
            'route_stop_id' => ['nullable', 'uuid'],
        ]);

        $count = $this->passengers->recordLeftBehind(
            $trip,
            $validated['student_ids'],
            $validated['route_stop_id'] ?? null,
            $request->user(),
        );

        return $this->success(['recorded' => $count],
            "{$count} student(s) recorded as left behind. Operations has been alerted.");
    }

    private function findTrip(string $id): Trip
    {
        $trip = Trip::with('bus')->find($id);

        if (! $trip) {
            throw new ResourceNotFoundException('Trip not found.');
        }

        return $trip;
    }

    /**
     * A stop id paired with a trip it does not belong to must not resolve.
     */
    private function findProgress(Trip $trip, string $stopId): TripStopProgress
    {
        $progress = TripStopProgress::with('stop')
            ->where('trip_id', $trip->getKey())
            ->where('route_stop_id', $stopId)
            ->first();

        if (! $progress) {
            throw new ResourceNotFoundException('Stop not found on this trip.');
        }

        return $progress;
    }
}
