<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationCategory;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The notification centre (SH-15).
 *
 * Every action is scoped to the caller's own notifications. There is no
 * endpoint by which one user reaches another's, at any privilege level —
 * BR-400 makes the recipient the only reader.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['sometimes', Rule::enum(NotificationCategory::class)],
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Notification::forUser($request->user()->getKey());

        if (isset($filters['category'])) {
            $query->where('category', strtoupper($filters['category']));
        }

        if ($request->boolean('unread')) {
            $query->unread();
        }

        $notifications = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null, default: 20));

        return $this->paginated($notifications, 'Notifications retrieved successfully.');
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Drives the badge on every screen, so it is deliberately cheap.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread' => Notification::forUser($request->user()->getKey())->unread()->count(),
        ], 'Unread count retrieved successfully.');
    }

    /**
     * GET /api/v1/notifications/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwnNotification($request, $id);

        return $this->success($notification->load('deliveries'), 'Notification retrieved successfully.');
    }

    /**
     * PATCH /api/v1/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwnNotification($request, $id);

        $notification->markRead();

        return $this->success($notification->fresh(), 'Notification marked as read.');
    }

    /**
     * PATCH /api/v1/notifications/{id}/unread
     */
    public function markUnread(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwnNotification($request, $id);

        $notification->markUnread();

        return $this->success($notification->fresh(), 'Notification marked as unread.');
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = Notification::forUser($request->user()->getKey())
            ->unread()
            ->update(['read_at' => now()]);

        return $this->success(['marked' => $count], "{$count} notification(s) marked as read.");
    }

    /**
     * DELETE /api/v1/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = $this->findOwnNotification($request, $id);

        $notification->delete();

        return $this->success(null, 'Notification removed.');
    }

    /**
     * A notification belonging to anyone else does not exist as far as this
     * caller is concerned — the response must not confirm it is out there.
     */
    private function findOwnNotification(Request $request, string $id): Notification
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        if (! $notification) {
            throw new ResourceNotFoundException('Notification not found.');
        }

        return $notification;
    }
}
