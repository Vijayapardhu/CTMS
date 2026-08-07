<?php

namespace App\Http\Controllers\Api;

use App\Enums\DevicePlatform;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\NotificationDevice;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Push device registration.
 *
 * Multi-device by design — a parent with a phone and a tablet is registered
 * on both and receives on both.
 */
class NotificationDeviceController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/notification-devices
     */
    public function index(Request $request): JsonResponse
    {
        $devices = NotificationDevice::where('user_id', $request->user()->getKey())
            ->active()
            ->latest('last_used_at')
            ->get();

        return $this->success($devices, 'Registered devices retrieved successfully.');
    }

    /**
     * POST /api/v1/notification-devices
     *
     * Registration is idempotent: an app that re-registers on every launch —
     * which is the recommended client behaviour — must not accumulate rows.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:512'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'device_name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $hash = NotificationDevice::hashToken($validated['token']);

        $device = DB::transaction(function () use ($validated, $user, $hash) {
            $existing = NotificationDevice::where('token_hash', $hash)->first();

            // A token the OS reassigned to a different account must MOVE, not
            // duplicate. Leaving it on the old account would keep sending one
            // family's child-boarding notifications to a stranger's phone.
            $device = $existing ?? new NotificationDevice;

            $device->forceFill([
                'user_id' => $user->getKey(),
                'token' => $validated['token'],
                'token_hash' => $hash,
                'platform' => strtoupper($validated['platform']),
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'last_used_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
            ])->save();

            return $device;
        });

        return $this->success($device->fresh(), 'Device registered successfully.');
    }

    /**
     * DELETE /api/v1/notification-devices/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $device = NotificationDevice::where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->first();

        if (! $device) {
            throw new ResourceNotFoundException('Device not found.');
        }

        $device->revoke('Revoked by the account holder.');

        return $this->success(null, 'Device revoked successfully.');
    }

    /**
     * POST /api/v1/notification-devices/revoke-all
     *
     * The companion to signing out everywhere: stop push reaching devices the
     * user no longer holds.
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $devices = NotificationDevice::where('user_id', $request->user()->getKey())
            ->active()
            ->get();

        foreach ($devices as $device) {
            $device->revoke('Revoked by the account holder.');
        }

        return $this->success(['revoked' => $devices->count()],
            "{$devices->count()} device(s) revoked.");
    }
}
