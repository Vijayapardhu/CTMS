<?php

namespace App\Http\Controllers\Api;

use App\Events\Auth\PasswordChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\AuditLogger;
use App\Services\Auth\TokenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/auth/change-password
     *
     * Changing a password invalidates every existing session. If the old
     * password leaked, the attacker's tokens die the moment the owner
     * rotates it — otherwise a stolen token would outlive the password.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->password = $request->validated('password');
        $user->save();

        $this->tokens->revokeAllForUser($user);

        $this->audit->log(
            action: 'PASSWORD_CHANGED',
            table: 'users',
            recordId: $user->getKey(),
            actor: $user,
        );

        // N-39 — if this was not them, this is the message that tells them
        // the account is compromised.
        PasswordChanged::dispatch($user);

        return $this->success(
            null,
            'Password changed successfully. Please sign in again.',
        );
    }
}
