<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverNotification;
use App\Models\NotificationDelivery;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The delivery log (AD-94) — administrators only.
 *
 * "Was the parent told?" must be answerable months later, and a failing
 * channel must be visible as an operational incident rather than buried as a
 * statistic.
 */
class NotificationLogController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/notification-log
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
            'event_key' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = NotificationDelivery::with(['notification.user']);

        if (isset($filters['channel'])) {
            $query->where('channel', strtoupper($filters['channel']));
        }

        if (isset($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        foreach (['event_key' => 'event_key', 'user_id' => 'user_id'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->whereHas('notification',
                    fn ($q) => $q->where($column, $filters[$filter]));
            }
        }

        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $deliveries = $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null));

        return $this->paginated($deliveries, 'Delivery log retrieved successfully.');
    }

    /**
     * GET /api/v1/notification-log/health
     *
     * Per-channel delivery rates. A failing channel is an operational
     * incident, so it is surfaced as a headline rather than left to be
     * inferred from a list.
     */
    public function health(Request $request): JsonResponse
    {
        $since = now()->subDay();

        $rows = NotificationDelivery::query()
            ->where('created_at', '>=', $since)
            ->select('channel', 'status', DB::raw('count(*) as total'))
            ->groupBy('channel', 'status')
            ->get();

        $health = [];

        foreach (NotificationChannel::cases() as $channel) {
            $forChannel = $rows->where('channel', $channel);

            $delivered = (int) $forChannel->where('status', DeliveryStatus::DELIVERED)->sum('total');
            $failed = (int) $forChannel->where('status', DeliveryStatus::PERMANENTLY_FAILED)->sum('total');
            $attempted = $delivered + $failed;

            $health[] = [
                'channel' => $channel->value,
                'enabled' => $channel->isEnabled(),
                'delivered' => $delivered,
                'failed' => $failed,
                'suppressed' => (int) $forChannel->where('status', DeliveryStatus::SUPPRESSED)->sum('total'),
                'pending' => (int) $forChannel->whereIn('status', [
                    DeliveryStatus::QUEUED, DeliveryStatus::RETRYING, DeliveryStatus::SENT,
                ])->sum('total'),
                'success_rate' => $attempted === 0 ? null : round($delivered / $attempted * 100, 1),
            ];
        }

        return $this->success([
            'window_hours' => 24,
            'channels' => $health,
        ], 'Delivery health retrieved successfully.');
    }

    /**
     * POST /api/v1/notification-log/{id}/resend
     *
     * Replay a permanently failed delivery once the underlying cause is fixed.
     */
    public function resend(string $id): JsonResponse
    {
        $delivery = NotificationDelivery::with('notification')->find($id);

        if (! $delivery) {
            throw new ResourceNotFoundException('Delivery not found.');
        }

        // Replaying a delivered message would tell somebody the same thing
        // twice, which BR-405 exists to prevent.
        if ($delivery->status === DeliveryStatus::DELIVERED) {
            throw new BusinessRuleException('This notification was already delivered.');
        }

        if (! $delivery->channel->isEnabled()) {
            throw new BusinessRuleException(
                "The {$delivery->channel->label()} channel is disabled for this installation.",
            );
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::QUEUED,
            'attempts' => 0,
            'next_attempt_at' => null,
            'reason' => null,
        ])->save();

        DeliverNotification::dispatch($delivery->getKey())
            ->onQueue($delivery->notification->priority->queue());

        return $this->success($delivery->fresh(), 'Delivery has been queued for another attempt.');
    }
}
