<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Builds error responses in the standard API envelope.
 *
 * Lives in a class rather than a helper function because the exception handler
 * is registered from bootstrap/app.php, which is re-required on every
 * application boot (notably once per test).
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>|null  $errors
     */
    public static function response(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'code' => $status,
        ], $status);
    }
}
