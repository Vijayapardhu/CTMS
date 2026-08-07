<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Generate an OpenAPI 3.1 document from the running router.
 *
 * Derived, never hand-written. A specification maintained by hand drifts from
 * the code within a release and is then worse than no specification, because
 * a client team trusts it. This reads the actual routes, the actual middleware
 * and the actual FormRequest rules, so the only way for it to be wrong is for
 * the code to be wrong.
 *
 *     php artisan openapi:generate
 *     php artisan openapi:generate --output=../docs/driver-app/openapi.json
 */
class GenerateOpenApi extends Command
{
    protected $signature = 'openapi:generate
        {--output=openapi.json : Where to write the document}
        {--role= : Emit only endpoints reachable by this role, e.g. DRIVER}';

    protected $description = 'Generate an OpenAPI 3.1 document from the router and FormRequest rules';

    public function handle(): int
    {
        $role = $this->option('role') ? strtoupper($this->option('role')) : null;

        $paths = [];
        $counted = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = implode(',', array_filter($route->gatherMiddleware(), 'is_string'));

            if ($role !== null && ! $this->reachableBy($middleware, $role)) {
                continue;
            }

            $path = '/'.preg_replace('/\{(\w+)\??\}/', '{$1}', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $middleware, $method);
                $counted++;
            }
        }

        ksort($paths);

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'CTMS API',
                'version' => '1.0.0',
                'description' => "Campus Transport Management System.\n\n"
                    ."Generated from the router — do not edit by hand. Regenerate with\n"
                    ."`php artisan openapi:generate`.\n\n"
                    ."Every response uses the envelope `{ success, message, data, code }`.\n"
                    .'Paginated responses add a `pagination` object.',
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/').'/api/v1', 'description' => 'This deployment'],
            ],
            'components' => $this->components(),
            'security' => [['bearerAuth' => []]],
            'paths' => $paths,
        ];

        $output = $this->option('output');
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents(base_path($output), $json."\n");

        $this->info("Wrote {$counted} operations across ".count($paths)." paths to {$output}");

        return self::SUCCESS;
    }

    /**
     * Whether a role passes the route's coarse gate.
     *
     * Note this is *routability*, not authorization. Record-level scope lives
     * in policies and cannot be read from the router — the generated document
     * says so in each operation's description rather than pretending otherwise.
     */
    private function reachableBy(string $middleware, string $role): bool
    {
        if (! preg_match('/role:([A-Z,]+)/', $middleware, $m)) {
            return true;
        }

        return in_array($role, explode(',', $m[1]), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RoutingRoute $route, string $middleware, string $method): array
    {
        $operation = [
            'summary' => $this->summarise($route),
            'operationId' => $this->operationId($route, $method),
            'tags' => [$this->tag($route)],
            'description' => $this->describe($middleware),
        ];

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = $this->requestBody($route);

            if ($body !== null) {
                $operation['requestBody'] = $body;
            }
        }

        $operation['responses'] = $this->responses($method, $middleware);

        if (! str_contains($middleware, 'auth.jwt')) {
            $operation['security'] = [];
        }

        return $operation;
    }

    /**
     * Build a request schema from the route's FormRequest, when it has one.
     *
     * @return array<string, mixed>|null
     */
    private function requestBody(RoutingRoute $route): ?array
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return null;
        }

        [$class, $methodName] = explode('@', $action);

        if (! class_exists($class) || ! method_exists($class, $methodName)) {
            return null;
        }

        $formRequest = null;

        foreach ((new ReflectionMethod($class, $methodName))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $name = $type->getName();

            if (class_exists($name) && is_subclass_of($name, FormRequest::class)) {
                $formRequest = $name;
                break;
            }
        }

        if ($formRequest === null) {
            return null;
        }

        try {
            $rules = (new $formRequest)->rules();
        } catch (\Throwable) {
            // Some rule sets depend on the incoming payload. The endpoint is
            // still documented; only its schema is omitted, which is honest.
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            $rule = is_array($rule) ? $rule : explode('|', (string) $rule);
            $flat = implode('|', array_map(fn ($r) => is_object($r) ? $r::class : (string) $r, $rule));

            // Nested `items.*.field` notation is flattened to its parent.
            if (str_contains($field, '.')) {
                $parent = explode('.', $field)[0];
                $properties[$parent] ??= ['type' => 'array', 'items' => ['type' => 'object']];

                continue;
            }

            $properties[$field] = $this->schemaFor($flat);

            if (str_contains($flat, 'required')) {
                $required[] = $field;
            }
        }

        if ($properties === []) {
            return null;
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        // Multipart where a file is expected, JSON otherwise.
        $contentType = str_contains(implode('', array_keys($properties)), 'file')
            ? 'multipart/form-data'
            : 'application/json';

        return ['required' => $required !== [], 'content' => [$contentType => ['schema' => $schema]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaFor(string $rule): array
    {
        $schema = match (true) {
            str_contains($rule, 'boolean') => ['type' => 'boolean'],
            str_contains($rule, 'integer') => ['type' => 'integer'],
            str_contains($rule, 'numeric') => ['type' => 'number'],
            str_contains($rule, 'array') => ['type' => 'array', 'items' => ['type' => 'string']],
            str_contains($rule, 'file') => ['type' => 'string', 'format' => 'binary'],
            str_contains($rule, 'date') => ['type' => 'string', 'format' => 'date-time'],
            str_contains($rule, 'uuid') => ['type' => 'string', 'format' => 'uuid'],
            str_contains($rule, 'email') => ['type' => 'string', 'format' => 'email'],
            default => ['type' => 'string'],
        };

        if (preg_match('/\bmax:(\d+)/', $rule, $m)) {
            $key = $schema['type'] === 'string' ? 'maxLength' : 'maximum';
            $schema[$key] = (int) $m[1];
        }

        if (preg_match('/\bmin:(\d+)/', $rule, $m)) {
            $key = $schema['type'] === 'string' ? 'minLength' : 'minimum';
            $schema[$key] = (int) $m[1];
        }

        if (str_contains($rule, 'nullable')) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function responses(string $method, string $middleware): array
    {
        $ok = $method === 'POST' ? '201' : '200';

        $responses = [
            $ok => [
                'description' => 'Success',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]],
            ],
        ];

        if (str_contains($middleware, 'auth.jwt')) {
            $responses['401'] = $this->errorResponse('Not authenticated, or the account has been deactivated');
            $responses['403'] = $this->errorResponse('Authenticated but not permitted — do not retry');
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $responses['409'] = $this->errorResponse(
                'A business rule refused. The message is written for the end user; show it verbatim'
            );
            $responses['422'] = $this->errorResponse('Validation failed; see `errors`');
        }

        if (str_contains($middleware, 'throttle')) {
            $responses['429'] = $this->errorResponse('Rate limited — back off and retry');
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
            ],
            'schemas' => [
                'Envelope' => [
                    'type' => 'object',
                    'required' => ['success', 'message', 'code'],
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                        'message' => ['type' => 'string'],
                        'data' => ['description' => 'Payload; shape varies by endpoint'],
                        'code' => ['type' => 'integer'],
                        'pagination' => ['$ref' => '#/components/schemas/Pagination'],
                    ],
                ],
                'Pagination' => [
                    'type' => 'object',
                    'properties' => [
                        'total' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer', 'maximum' => 100],
                        'current_page' => ['type' => 'integer'],
                        'last_page' => ['type' => 'integer'],
                    ],
                ],
                'Error' => [
                    'type' => 'object',
                    'required' => ['success', 'message', 'code'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'enum' => [false]],
                        'message' => ['type' => 'string'],
                        'data' => ['type' => 'null'],
                        'errors' => [
                            'type' => 'object',
                            'description' => 'Field errors for 422; rule context for 409',
                            'additionalProperties' => true,
                        ],
                        'code' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The per-minute ceiling for a named limiter.
     *
     * Mirrors AppServiceProvider::configureRateLimiting(). Kept here rather
     * than introspected because Laravel's limiters are closures and their
     * ceilings are not readable without invoking them against a request.
     */
    private function limitFor(string $name): int
    {
        return match ($name) {
            'api' => 120,
            'gps' => 60,
            'writes' => 60,
            'auth' => 5,
            'password' => 5,
            default => 0,
        };
    }

    private function tag(RoutingRoute $route): string
    {
        $segments = explode('/', $route->uri());

        return Str::headline($segments[2] ?? 'general');
    }

    private function summarise(RoutingRoute $route): string
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return $route->uri();
        }

        [$class, $method] = explode('@', $action);

        return Str::headline($method).' — '.Str::headline(class_basename($class));
    }

    private function operationId(RoutingRoute $route, string $method): string
    {
        return Str::camel(strtolower($method).'_'.str_replace(
            ['api/v1/', '/', '{', '}', '-'],
            ['', '_', '', '', '_'],
            $route->uri()
        ));
    }

    private function describe(string $middleware): string
    {
        $notes = [];

        if (preg_match('/role:([A-Z,]+)/', $middleware, $m)) {
            $notes[] = 'Roles: '.$m[1].'.';
        }

        if (preg_match('/access:([A-Z_]+)/', $middleware, $m)) {
            $notes[] = 'Requires administrator tier '.$m[1].' or above.';
        }

        // A route can sit inside a throttled group *and* carry its own
        // limiter — GPS ingest does exactly that. Both apply and the stricter
        // binds, so reporting only the first would understate the ceiling a
        // client has to respect.
        if (preg_match_all('/throttle:(\w+)/', $middleware, $m)) {
            $limits = array_map(fn (string $name) => $name.' ('.$this->limitFor($name).'/min)',
                array_unique($m[1]));

            $notes[] = count($limits) > 1
                ? 'Rate limited by '.implode(' and ', $limits).'; the stricter applies.'
                : 'Rate limited by '.$limits[0].'.';
        }

        $notes[] = 'Record-level scope is enforced by policy and is not visible '
            .'in this document — an endpoint listed here may still return 403 '
            .'for a record the caller does not own.';

        return implode(' ', $notes);
    }
}
