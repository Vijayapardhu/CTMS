<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Events\Auth\AccountDeactivated;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\TokenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * User account administration (FR-01).
 */
class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly TokenService $tokens,
    ) {}

    /**
     * GET /api/v1/users — administrators only.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // The scheduler's own identity (BR-512) is an audit subject, not a
        // colleague. It has no inbox, cannot log in, and must never appear in
        // a staff list where somebody might try to manage it.
        $query = User::query()->human();

        if (isset($filters['role'])) {
            $query->where('role', strtoupper($filters['role']));
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($users, 'Users retrieved successfully.');
    }

    /**
     * GET /api/v1/users/{user} — self or administrator.
     */
    public function show(string $id): JsonResponse
    {
        $user = $this->findUser($id);

        $this->authorize('view', $user);

        return $this->success($this->auth->presentUser($user), 'User retrieved successfully.');
    }

    /**
     * PUT /api/v1/users/{user} — self or administrator.
     */
    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);

        $this->authorize('update', $user);

        $before = $user->getAttributes();

        // Only whitelisted, validated profile fields reach the model. `role`
        // and `is_active` are not among them by design.
        $user->fill($request->validated());
        $user->save();

        $this->audit->updated($user, $before, $request->user());

        return $this->success($this->auth->presentUser($user->fresh()), 'User updated successfully.');
    }

    /**
     * PATCH /api/v1/users/{user}/status — administrators only.
     *
     * Deactivating an account revokes its tokens immediately rather than
     * letting the current session run until the token expires.
     */
    public function setActiveState(Request $request, string $id): JsonResponse
    {
        $user = $this->findUser($id);

        $this->authorize('setActiveState', $user);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        // BR-010 — a minimum number of administrators must remain reachable.
        // Locked and counted inside a transaction: two administrators
        // deactivating the third simultaneously would each see two remaining
        // and both succeed, locking everybody out of the system.
        if (! $request->boolean('is_active')) {
            DB::transaction(function () use ($user) {
                $this->assertAdministratorsRemain($user);
            });
        }

        $before = $user->getAttributes();

        $user->is_active = $request->boolean('is_active');
        $user->save();

        if (! $user->is_active) {
            // Published before the tokens die, so the notification platform
            // still sees an entitled recipient (BR-401). A user who simply
            // stops being able to sign in with no explanation is a support
            // call and a stranded rider.
            AccountDeactivated::dispatch($user);

            $this->tokens->revokeAllForUser($user);
        }

        $this->audit->updated($user, $before, $request->user());

        return $this->success(
            $this->auth->presentUser($user->fresh()),
            $user->is_active ? 'Account activated.' : 'Account deactivated.',
        );
    }

    /**
     * BR-010 — refuse to deactivate the last administrators.
     *
     * A deployment with no reachable administrator cannot be recovered through
     * the product: there is no endpoint that can reactivate an account without
     * an administrator to call it. That is a database-console incident, at
     * whatever hour it happens.
     *
     * @throws BusinessRuleException
     */
    private function assertAdministratorsRemain(User $target): void
    {
        if (! $target->isAdmin() || ! $target->is_active) {
            return;
        }

        $minimum = (int) config('ctms.access.minimum_active_admins', 2);

        $remaining = User::query()
            ->human()
            ->role(UserRole::ADMIN)
            ->active()
            ->whereKeyNot($target->getKey())
            ->lockForUpdate()
            ->count();

        if ($remaining < $minimum) {
            throw new BusinessRuleException(
                "At least {$minimum} administrators must remain active. Promote another "
                .'account before deactivating this one.',
                ['minimum_active_admins' => $minimum, 'would_remain' => $remaining],
            );
        }
    }

    /**
     * Load a user or fail with a 404 in the standard envelope.
     */
    private function findUser(string $id): User
    {
        $user = User::find($id);

        if (! $user) {
            throw new ResourceNotFoundException('User not found.');
        }

        return $user;
    }
}
