<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The single response envelope for the whole API.
 *
 * Every endpoint returns the same shape so clients never have to branch on
 * response format:
 *   { "success": bool, "message": string, "data": mixed, "code": int }
 */
trait ApiResponse
{
    /**
     * A successful response.
     */
    protected function success(mixed $data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $code,
        ], $code);
    }

    /**
     * A newly created resource (201).
     */
    protected function created(mixed $data = null, string $message = 'Created successfully.'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * A failed response. `$errors` carries field-level detail for 422s.
     */
    protected function error(string $message = 'Error', int $code = 500, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
            'code' => $code,
        ], $code);
    }

    /**
     * A paginated collection.
     *
     * Accepts the LengthAwarePaginator contract, which is what
     * `Builder::paginate()` actually returns.
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Success',
        int $code = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'code' => $code,
        ], $code);
    }

    /**
     * Resolve a safe page size from the request.
     *
     * Hard-capped so a client cannot ask for the entire table in one call.
     */
    protected function perPage(mixed $requested, int $default = 15, int $max = 100): int
    {
        $perPage = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($perPage, $max));
    }
}
