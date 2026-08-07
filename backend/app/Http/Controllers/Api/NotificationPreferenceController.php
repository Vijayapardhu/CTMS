<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\UpdatePreferencesRequest;
use App\Models\NotificationPreference;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification preferences (SH-14).
 */
class NotificationPreferenceController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/notification-preferences
     *
     * Returns every category, including ones the user has never touched, with
     * the defaults filled in — a settings screen must show the real current
     * state, not an empty list.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stored = NotificationPreference::where('user_id', $user->getKey())
            ->get()
            ->keyBy(fn (NotificationPreference $preference) => $preference->category->value);

        $categories = array_map(function (NotificationCategory $category) use ($stored) {
            $preference = $stored->get($category->value);

            return [
                'category' => $category->value,
                'label' => $category->label(),
                // BR-404 — locked categories are shown as locked with an
                // explanation. Hiding the fact breeds distrust.
                'mutable' => $category->isMutable(),
                'locked_reason' => $category->isMutable()
                    ? null
                    : 'Safety and security notifications cannot be turned off.',
                'muted' => $category->isMutable() ? ($preference?->muted ?? false) : false,
                'channels' => array_map(
                    fn (NotificationChannel $channel) => $channel->value,
                    $preference?->selectedChannels() ?? $category->defaultChannels(),
                ),
            ];
        }, NotificationCategory::cases());

        return $this->success([
            'categories' => $categories,
            'available_channels' => array_map(fn (NotificationChannel $channel) => [
                'channel' => $channel->value,
                'label' => $channel->label(),
                'enabled' => $channel->isEnabled(),
            ], NotificationChannel::cases()),
            'quiet_hours' => [
                'start' => $user->quiet_hours_start,
                'end' => $user->quiet_hours_end,
            ],
        ], 'Notification preferences retrieved successfully.');
    }

    /**
     * PUT /api/v1/notification-preferences
     */
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        foreach ($request->validated('categories', []) as $entry) {
            $category = NotificationCategory::from(strtoupper($entry['category']));

            // BR-404 — a non-mutable category cannot be muted, whatever the
            // payload says. Rejecting the whole request would be unhelpful;
            // the setting is simply not applied and the response shows why.
            $muted = $category->isMutable() ? (bool) ($entry['muted'] ?? false) : false;

            // `user_id` is taken from the token and set explicitly — it is
            // never mass-assignable, so nobody can edit another person's
            // preferences by adding a field to the payload.
            $preference = NotificationPreference::where('user_id', $user->getKey())
                ->where('category', $category->value)
                ->first() ?? new NotificationPreference;

            $preference->forceFill([
                'user_id' => $user->getKey(),
                'category' => $category->value,
                'channels' => array_values(array_unique(array_map(
                    fn ($channel) => strtoupper((string) $channel),
                    $entry['channels'] ?? [],
                ))),
                'muted' => $muted,
            ])->save();
        }

        if ($request->has('quiet_hours')) {
            $user->forceFill([
                'quiet_hours_start' => $request->validated('quiet_hours.start'),
                'quiet_hours_end' => $request->validated('quiet_hours.end'),
            ])->save();
        }

        return $this->index($request);
    }
}
