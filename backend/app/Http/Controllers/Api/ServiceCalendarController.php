<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServiceDayType;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\ServiceCalendarDay;
use App\Models\Trip;
use App\Services\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Service calendar (AD-61, BR-264).
 *
 * Which days the service does not run. Trip generation reads this; declaring
 * a suspension for a date that already has trips reports the consequence
 * before committing to it.
 */
class ServiceCalendarController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/v1/service-calendar
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'day_type' => ['sometimes', Rule::enum(ServiceDayType::class)],
        ]);

        $query = ServiceCalendarDay::with('declaredBy');

        $query->whereDate('date', '>=', $filters['from'] ?? now()->startOfYear()->toDateString());

        if (isset($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }

        if (isset($filters['day_type'])) {
            $query->where('day_type', strtoupper($filters['day_type']));
        }

        return $this->success(
            $query->orderBy('date')->get(),
            'Service calendar retrieved successfully.',
        );
    }

    /**
     * POST /api/v1/service-calendar
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => [
                'required',
                'date',
                // A plain `unique` rule compares the raw column, which stores a
                // midnight time component — so it never matches a bare date and
                // the duplicate reaches the database as a 500. Compare by date.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $exists = ServiceCalendarDay::whereDate('date', Carbon::parse($value)->toDateString())
                        ->exists();

                    if ($exists) {
                        $fail('This date has already been declared.');
                    }
                },
            ],
            'day_type' => ['required', Rule::enum(ServiceDayType::class)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $day = new ServiceCalendarDay([
            'date' => $validated['date'],
            'day_type' => strtoupper($validated['day_type']),
            'reason' => $validated['reason'],
        ]);

        $day->declared_by_id = $request->user()->getKey();
        $day->save();

        $this->audit->created($day, $request->user());

        // Declaring a suspension does not itself cancel trips — that is a
        // separate, deliberate act with its own notifications (BR-262). The
        // count is reported so the operator knows what still needs doing.
        $affected = $day->day_type->suspendsService()
            ? Trip::query()->unfinished()
                ->whereDate('trip_date', Carbon::parse($validated['date'])->toDateString())
                ->count()
            : 0;

        return $this->created([
            'day' => $day,
            'trips_already_scheduled' => $affected,
        ], $affected > 0
            ? "Recorded. {$affected} trip(s) are already scheduled on this date and still need cancelling."
            : 'Recorded.');
    }

    /**
     * DELETE /api/v1/service-calendar/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $day = ServiceCalendarDay::find($id);

        if (! $day) {
            throw new ResourceNotFoundException('Calendar entry not found.');
        }

        $day->delete();

        $this->audit->deleted($day, $request->user());

        return $this->success(null, 'Calendar entry removed. The service runs normally on this date.');
    }
}
