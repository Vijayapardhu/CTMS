<?php

use App\Exceptions\AuthenticationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Middleware\AuthenticateRequest;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\LogRequestResponse;
use App\Http\Middleware\RequireAccessLevel;
use App\Http\Middleware\RoleAuthorize;
use App\Support\ApiError;
use Illuminate\Auth\Access\AuthorizationException as LaravelAuthorizationException;
use Illuminate\Auth\AuthenticationException as LaravelAuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Broadcast channel authorization (BR-304). Evaluated at subscribe
        // and again on every reconnect.
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.jwt' => AuthenticateRequest::class,
            'role' => RoleAuthorize::class,
            // The second axis: which administrator tier (BR-019 groundwork).
            // `role:ADMIN` says who you are; `access:OPERATIONS` says what
            // that entitles you to.
            'access' => RequireAccessLevel::class,
        ]);

        // Applied to every API request: a correlation id for tracing and a
        // request/response log with credentials stripped.
        $middleware->api(append: [
            CorrelationId::class,
            LogRequestResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Only JSON is ever returned from the API surface.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // ---- Domain exceptions -------------------------------------------

        $exceptions->render(fn (AuthenticationException $e) => ApiError::response($e->getMessage(), 401));

        $exceptions->render(fn (AuthorizationException $e) => ApiError::response($e->getMessage(), 403));

        $exceptions->render(fn (ResourceNotFoundException $e) => ApiError::response($e->getMessage(), 404));

        $exceptions->render(fn (BusinessRuleException $e) => ApiError::response(
            $e->getMessage(),
            409,
            $e->getContext() ?: null,
        ));

        $exceptions->render(fn (ValidationException $e) => ApiError::response(
            $e->getMessage(),
            422,
            $e->getErrors() ?: null,
        ));

        // ---- Framework exceptions ----------------------------------------

        $exceptions->render(fn (LaravelValidationException $e) => ApiError::response(
            'The given data was invalid.',
            422,
            $e->errors(),
        ));

        $exceptions->render(fn (LaravelAuthenticationException $e) => ApiError::response(
            'Authentication is required.',
            401,
        ));

        $exceptions->render(fn (LaravelAuthorizationException $e) => ApiError::response(
            $e->getMessage() ?: 'You do not have permission to perform this action.',
            403,
        ));

        // Never echo the model class name — it leaks internal structure.
        $exceptions->render(fn (ModelNotFoundException $e) => ApiError::response('Resource not found.', 404));

        $exceptions->render(fn (NotFoundHttpException $e) => ApiError::response('Endpoint not found.', 404));

        $exceptions->render(fn (MethodNotAllowedHttpException $e) => ApiError::response(
            'This method is not allowed for this endpoint.',
            405,
        ));

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            $response = ApiError::response('Too many requests. Please slow down.', 429);

            if ($retryAfter = $e->getHeaders()['Retry-After'] ?? null) {
                $response->headers->set('Retry-After', $retryAfter);
            }

            return $response;
        });

        // ---- Catch-all ----------------------------------------------------

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null; // Let the framework handle non-API requests.
            }

            // Any remaining HTTP exception keeps its intended status.
            if ($e instanceof HttpExceptionInterface) {
                return ApiError::response(
                    $e->getMessage() ?: 'Request failed.',
                    $e->getStatusCode(),
                );
            }

            report($e);

            // In production the client learns nothing about the internals.
            // Locally the message is surfaced to keep debugging practical.
            return ApiError::response(
                config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred. Please try again later.',
                500,
            );
        });
    })->create();
