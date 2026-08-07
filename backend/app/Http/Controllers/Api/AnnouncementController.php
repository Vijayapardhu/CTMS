<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Http\Requests\Announcement\WithdrawAnnouncementRequest;
use App\Models\Announcement;
use App\Services\Communication\AnnouncementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Service announcements (blueprint §Communication).
 */
class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AnnouncementService $announcements) {}

    /**
     * GET /api/v1/announcements
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Announcement::class);

        $filters = $request->validate([
            'include_drafts' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $query = Announcement::with('createdBy');

        if ($actor->isAdmin()) {
            // Operations sees drafts and withdrawn notices too, so a draft can
            // be found again after it is written.
            if (! $request->boolean('include_drafts')) {
                $query->active();
            }
        } else {
            // Everyone else sees only what is live and addressed to them.
            $query->active()->forRole($actor->role);
        }

        return $this->paginated(
            $query->byImportance()->paginate($this->perPage($filters['per_page'] ?? null)),
            'Announcements retrieved successfully.',
        );
    }

    /**
     * GET /api/v1/announcements/{id}
     */
    public function show(string $id): JsonResponse
    {
        $announcement = $this->find($id);

        $this->authorize('view', $announcement);

        return $this->success($announcement->load('createdBy'), 'Announcement retrieved successfully.');
    }

    /**
     * POST /api/v1/announcements
     *
     * Drafts only. Publication is a separate call.
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $this->authorize('create', Announcement::class);

        return $this->created(
            $this->announcements->draft($request->validated(), $request->user()),
            'Announcement drafted. Publish it when you are ready to send it.',
        );
    }

    /**
     * PUT /api/v1/announcements/{id}
     */
    public function update(UpdateAnnouncementRequest $request, string $id): JsonResponse
    {
        $announcement = $this->find($id);

        $this->authorize('update', $announcement);

        return $this->success(
            $this->announcements->update($announcement, $request->validated(), $request->user()),
            'Announcement updated.',
        );
    }

    /**
     * POST /api/v1/announcements/{id}/publish
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $announcement = $this->find($id);

        $this->authorize('publish', $announcement);

        $announcement = $this->announcements->publish($announcement, $request->user());

        return $this->success(
            $announcement,
            "Announcement published to {$announcement->target_audience->label()}.",
        );
    }

    /**
     * POST /api/v1/announcements/{id}/withdraw
     */
    public function withdraw(WithdrawAnnouncementRequest $request, string $id): JsonResponse
    {
        $announcement = $this->find($id);

        $this->authorize('publish', $announcement);

        return $this->success(
            $this->announcements->withdraw($announcement, $request->validated()['reason'], $request->user()),
            'Announcement withdrawn. Notifications already sent cannot be recalled.',
        );
    }

    private function find(string $id): Announcement
    {
        $announcement = Announcement::find($id);

        if (! $announcement) {
            throw new ResourceNotFoundException('Announcement not found.');
        }

        return $announcement;
    }
}
