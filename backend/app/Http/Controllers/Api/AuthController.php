<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication endpoints (FR-01).
 */
class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $auth) {}

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        return $this->success($result, 'Logged in successfully.');
    }

    /**
     * POST /api/v1/auth/register
     *
     * Self-service registration creates students only. Driver and admin
     * accounts require an authenticated administrator (enforced in
     * RegisterRequest::authorize()).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated(), $request->user());

        return $this->created($result, 'Account created successfully.');
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Exchanges a refresh token for a fresh token pair. The presented refresh
     * token is consumed in the process.
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $result = $this->auth->refresh($validated['refresh_token']);

        return $this->success($result, 'Token refreshed successfully.');
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the access token presented with this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $claims = $request->attributes->get(AuthenticateRequest::CLAIMS_ATTRIBUTE);

        $this->auth->logout($claims, $request->user());

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * POST /api/v1/auth/logout-all
     *
     * Revokes every token belonging to the caller, on every device.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutEverywhere($request->user());

        return $this->success(null, 'Logged out from all devices.');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(
            $this->auth->presentUser($request->user()),
            'Profile retrieved successfully.',
        );
    }
}
