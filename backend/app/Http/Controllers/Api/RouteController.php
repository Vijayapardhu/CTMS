<?php

namespace App\Http\Controllers\Api;

use App\Enums\RouteStatus;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Route\StoreRouteRequest;
use App\Http\Requests\Route\UpdateRouteRequest;
use App\Http\Requests\Route\UpdateRouteStatusRequest;
use App\Http\Requests\RouteStop\StoreRouteStopRequest;
use App\Http\Requests\RouteStop\UpdateRouteStopRequest;
use App\Models\Route;
use App\Services\Network\RouteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Route and stop management (FR-05).
 */
class RouteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RouteService $routes) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Route::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(RouteStatus::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Route::query()->withCount('stops');

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        if (isset($filters['search'])) {
            $search = addcslashes($filters['search'], '%_\\');

            $query->where(function ($q) use ($search) {
                $q->where('route_name', 'like', "%{$search}%")
                    ->orWhere('route_code', 'like', "%{$search}%");
            });
        }

        $routes = $query->orderBy('route_code')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($routes, 'Routes retrieved successfully.');
    }

    public function store(StoreRouteRequest $request): JsonResponse
    {
        $this->authorize('create', Route::class);

        $route = $this->routes->create($request->validated(), $request->user());

        return $this->created($route, 'Route created successfully.');
    }

    public function show(string $id): JsonResponse
    {
        $route = $this->findRoute($id);

        $this->authorize('view', $route);

        $route->load('stops');

        return $this->success($route, 'Route retrieved successfully.');
    }

    public function update(UpdateRouteRequest $request, string $id): JsonResponse
    {
        $route = $this->findRoute($id);

        $this->authorize('update', $route);

        $route = $this->routes->update($route, $request->validated(), $request->user());

        return $this->success($route, 'Route updated successfully.');
    }

    public function updateStatus(UpdateRouteStatusRequest $request, string $id): JsonResponse
    {
        $route = $this->findRoute($id);

        $this->authorize('update', $route);

        $route = $this->routes->changeStatus($route, $request->status(), $request->user());

        return $this->success($route, "Route status updated to {$route->status->value}.");
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $route = $this->findRoute($id);

        $this->authorize('delete', $route);

        $this->routes->retire($route, $request->user());

        return $this->success(null, 'Route removed successfully.');
    }

    // ========================================================================
    // STOPS — always scoped to their parent route
    // ========================================================================

    public function listStops(string $id): JsonResponse
    {
        $route = $this->findRoute($id);

        $this->authorize('view', $route);

        return $this->success($route->stops()->get(), 'Route stops retrieved successfully.');
    }

    public function addStop(StoreRouteStopRequest $request, string $id): JsonResponse
    {
        $route = $this->findRoute($id);

        $this->authorize('manageStops', $route);

        $stop = $this->routes->addStop($route, $request->validated(), $request->user());

        return $this->created($stop, 'Stop added to route successfully.');
    }

    public function updateStop(UpdateRouteStopRequest $request, string $routeId, string $stopId): JsonResponse
    {
        $route = $this->findRoute($routeId);

        $this->authorize('manageStops', $route);

        $stop = $this->routes->updateStop($route, $stopId, $request->validated(), $request->user());

        return $this->success($stop, 'Stop updated successfully.');
    }

    public function deleteStop(Request $request, string $routeId, string $stopId): JsonResponse
    {
        $route = $this->findRoute($routeId);

        $this->authorize('manageStops', $route);

        $this->routes->deleteStop($route, $stopId, $request->user());

        return $this->success(null, 'Stop removed from route successfully.');
    }

    private function findRoute(string $id): Route
    {
        $route = Route::find($id);

        if (! $route) {
            throw new ResourceNotFoundException('Route not found.');
        }

        return $route;
    }
}
