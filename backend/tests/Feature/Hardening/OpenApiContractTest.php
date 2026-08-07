<?php

namespace Tests\Feature\Hardening;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The generated OpenAPI document must still describe the frozen API.
 *
 * The document is derived from the router, so it cannot drift from the code —
 * but it is committed, and a committed artefact goes stale the moment somebody
 * changes a route without regenerating. These tests fail when that happens,
 * which is the only thing that keeps a client team's generated models honest.
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $file): array
    {
        $path = base_path("../docs/driver-app/{$file}");

        $this->assertFileExists($path, "{$file} is missing — run `php artisan openapi:generate`.");

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function the_committed_document_matches_the_current_router(): void
    {
        $committed = $this->document('openapi-full.json');

        $this->artisan('openapi:generate', ['--output' => '../docs/driver-app/openapi-check.json'])
            ->assertSuccessful();

        $fresh = json_decode(
            (string) file_get_contents(base_path('../docs/driver-app/openapi-check.json')),
            true,
        );

        @unlink(base_path('../docs/driver-app/openapi-check.json'));

        $this->assertSame(
            array_keys($committed['paths']),
            array_keys($fresh['paths']),
            'The router and the committed OpenAPI document disagree. '
            .'Run `php artisan openapi:generate` and commit the result.',
        );
    }

    #[Test]
    public function the_driver_document_excludes_admin_only_endpoints(): void
    {
        $driver = $this->document('openapi-driver.json');
        $paths = array_keys($driver['paths']);

        // If any of these appear, the role filter has broken and a client team
        // will generate an SDK for endpoints that always return 403.
        foreach ([
            '/api/v1/audit-logs',
            '/api/v1/reports/trips',
            '/api/v1/consolidations',
            '/api/v1/replacements/{id}/approve',
            '/api/v1/preventive-maintenance',
        ] as $adminOnly) {
            $this->assertNotContains($adminOnly, $paths, "{$adminOnly} is admin-only and must not be in the driver document.");
        }
    }

    #[Test]
    public function the_driver_document_contains_the_critical_path(): void
    {
        $paths = array_keys($this->document('openapi-driver.json')['paths']);

        // The ten slices in the implementation order. A missing one means the
        // Driver App cannot be built from this document.
        foreach ([
            '/api/v1/auth/login',
            '/api/v1/auth/me',
            '/api/v1/trips',
            '/api/v1/inspections/checklist',
            '/api/v1/evidence',
            '/api/v1/buses/{id}/inspections',
            '/api/v1/trips/{id}/start',
            '/api/v1/trips/{id}/positions',
            '/api/v1/trips/{id}/live',
            '/api/v1/trips/{id}/board',
            '/api/v1/incidents',
            '/api/v1/trips/{id}/complete',
        ] as $required) {
            $this->assertContains($required, $paths, "{$required} is missing from the driver document.");
        }
    }

    #[Test]
    public function every_authenticated_operation_documents_its_refusals(): void
    {
        $document = $this->document('openapi-full.json');

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (($operation['security'] ?? null) === []) {
                    continue; // Explicitly public.
                }

                // A client that cannot see 401 and 403 in the contract will not
                // handle them, and the first time it meets one will be on a bus.
                $this->assertArrayHasKey('401', $operation['responses'],
                    strtoupper($method)." {$path} does not document 401.");
                $this->assertArrayHasKey('403', $operation['responses'],
                    strtoupper($method)." {$path} does not document 403.");
            }
        }
    }

    #[Test]
    public function the_gps_endpoint_reports_the_binding_rate_limit(): void
    {
        $operation = $this->document('openapi-driver.json')['paths']['/api/v1/trips/{id}/positions']['post'];

        // The route sits inside a 120/min group and carries its own 60/min
        // limiter. Reporting only one would understate the ceiling the client
        // has to sample within.
        $this->assertStringContainsString('gps (60/min)', $operation['description']);
        $this->assertStringContainsString('stricter applies', $operation['description']);
    }
}
