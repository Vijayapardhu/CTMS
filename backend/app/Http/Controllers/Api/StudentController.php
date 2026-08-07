<?php

namespace App\Http\Controllers\Api;

use App\Enums\StudentStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\AssignTransportRequest;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Student;
use App\Services\Governance\DataAccessLogger;
use App\Services\StudentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Student records and transport assignment (FR-04).
 */
class StudentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StudentService $students,
        private readonly DataAccessLogger $accessLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'route_id' => ['sometimes', 'uuid'],
            'department' => ['sometimes', 'string', 'max:100'],
            'unassigned' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Student::with(['user', 'route', 'pickupStop']);

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if (isset($filters['route_id'])) {
            $query->where('route_id', $filters['route_id']);
        }

        if (isset($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('route_id');
        }

        if (isset($filters['search'])) {
            $search = addcslashes($filters['search'], '%_\\');

            $query->where(function ($q) use ($search) {
                $q->where('registration_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $students = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($students, 'Students retrieved successfully.');
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $student = $this->students->create($request->validated(), $request->user());

        return $this->created($student, 'Student profile created successfully.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->authorize('view', $student);

        $student->load(['user', 'route', 'pickupStop', 'dropoffStop']);

        // BR-501 — a member of staff opening a child's record is an access
        // worth recording. The logger existed and nothing called it, so the
        // trail could answer "who changed this student" but not "who looked
        // at them", which is the question asked when something goes wrong.
        //
        // Reading your own record is not staff access and is not logged;
        // logging it would bury the entries that matter in noise.
        $actor = $request->user();

        if (! $actor->is($student->user)) {
            $this->accessLog->recordAccess(
                actor: $actor,
                subjectType: 'student',
                subjectId: (string) $student->getKey(),
                dataClass: 'STUDENT_RECORD',
                purpose: 'VIEW_RECORD',
            );
        }

        return $this->success($student, 'Student retrieved successfully.');
    }

    public function update(UpdateStudentRequest $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->authorize('update', $student);

        $data = $request->validated();

        // Ticket validity is a paid entitlement. A student editing their own
        // record must not be able to grant themselves one.
        if (! $request->user()->isAdmin()) {
            unset($data['has_valid_ticket'], $data['ticket_expiry_date'], $data['registration_number']);
        }

        $student = $this->students->update($student, $data, $request->user());

        return $this->success($student, 'Student updated successfully.');
    }

    /**
     * POST /api/v1/students/{id}/assign-transport
     */
    public function assignTransport(AssignTransportRequest $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->authorize('assignTransport', $student);

        $route = Route::find($request->validated('route_id'));
        $pickup = RouteStop::find($request->validated('pickup_stop_id'));
        $dropoffId = $request->validated('dropoff_stop_id');
        $dropoff = $dropoffId ? RouteStop::find($dropoffId) : null;

        if (! $route || ! $pickup) {
            throw new ResourceNotFoundException('The selected route or stop was not found.');
        }

        $student = $this->students->assignTransport(
            $student,
            $route,
            $pickup,
            $dropoff,
            $request->user(),
            $request->validated('capacity_override_reason'),
        );

        return $this->success($student, 'Transport assigned successfully.');
    }

    /**
     * DELETE /api/v1/students/{id}/assign-transport
     */
    public function clearTransport(Request $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->authorize('assignTransport', $student);

        $student = $this->students->clearTransport($student, $request->user());

        return $this->success($student, 'Transport assignment cleared.');
    }

    /**
     * PATCH /api/v1/students/{id}/status
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->authorize('changeStatus', $student);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(StudentStatus::class)],
        ]);

        $student = $this->students->changeStatus(
            $student,
            StudentStatus::from(strtoupper($validated['status'])),
            $request->user(),
        );

        return $this->success($student, "Student status updated to {$student->status->value}.");
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $student = $this->findStudent($id);

        $this->authorize('delete', $student);

        $this->students->delete($student, $request->user());

        return $this->success(null, 'Student removed successfully.');
    }

    private function findStudent(string $id): Student
    {
        $student = Student::find($id);

        if (! $student) {
            throw new ResourceNotFoundException('Student not found.');
        }

        return $student;
    }
}
