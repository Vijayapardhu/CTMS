<?php

namespace App\Http\Controllers\Api;

use App\Enums\DayOfWeek;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Models\Schedule;
use App\Services\Network\ScheduleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Weekly schedule management (FR-05).
 */
class ScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ScheduleService $schedules) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Schedule::class);

        $filters = $request->validate([
            'route_id' => ['sometimes', 'uuid'],
            'bus_id' => ['sometimes', 'uuid'],
            'driver_id' => ['sometimes', 'uuid'],
            'day_of_week' => ['sometimes', Rule::enum(DayOfWeek::class)],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Schedule::with(['route', 'bus', 'driver.user']);

        foreach (['route_id', 'bus_id', 'driver_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['day_of_week'])) {
            $query->where('day_of_week', strtoupper($filters['day_of_week']));
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $schedules = $query->orderBy('day_of_week')->orderBy('departure_time')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($schedules, 'Schedules retrieved successfully.');
    }

    public function store(StoreScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', Schedule::class);

        $schedule = $this->schedules->create($request->validated(), $request->user());

        return $this->created($schedule, 'Schedule created successfully.');
    }

    public function show(string $id): JsonResponse
    {
        $schedule = $this->findSchedule($id);

        $this->authorize('view', $schedule);

        $schedule->load(['route.stops', 'bus', 'driver.user']);

        return $this->success($schedule, 'Schedule retrieved successfully.');
    }

    public function update(UpdateScheduleRequest $request, string $id): JsonResponse
    {
        $schedule = $this->findSchedule($id);

        $this->authorize('update', $schedule);

        $schedule = $this->schedules->update($schedule, $request->validated(), $request->user());

        return $this->success($schedule, 'Schedule updated successfully.');
    }

    /**
     * PATCH /api/v1/schedules/{id}/status — switch a schedule on or off.
     */
    public function setActive(Request $request, string $id): JsonResponse
    {
        $schedule = $this->findSchedule($id);

        $this->authorize('update', $schedule);

        $request->validate(['is_active' => ['required', 'boolean']]);

        $schedule = $this->schedules->setActive($schedule, $request->boolean('is_active'), $request->user());

        return $this->success(
            $schedule,
            $schedule->is_active ? 'Schedule activated.' : 'Schedule deactivated.',
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $schedule = $this->findSchedule($id);

        $this->authorize('delete', $schedule);

        $this->schedules->delete($schedule, $request->user());

        return $this->success(null, 'Schedule removed successfully.');
    }

    private function findSchedule(string $id): Schedule
    {
        $schedule = Schedule::find($id);

        if (! $schedule) {
            throw new ResourceNotFoundException('Schedule not found.');
        }

        return $schedule;
    }
}
