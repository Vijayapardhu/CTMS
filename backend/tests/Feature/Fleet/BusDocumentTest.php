<?php

namespace Tests\Feature\Fleet;

use App\Enums\DocumentType;
use App\Models\Bus;
use App\Models\BusDocument;
use App\Models\Driver;
use App\Models\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-055 — statutory vehicle documents.
 *
 * The rule this suite defends is a legal bar with no override: a bus whose
 * fitness certificate, insurance or permit has lapsed may not carry
 * passengers. Operating uninsured voids cover for everyone aboard.
 */
class BusDocumentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(array $overrides = []): array
    {
        return array_merge([
            'document_type' => 'INSURANCE',
            'document_number' => 'INS-9988776',
            'issuing_authority' => 'National Insurance Co.',
            'issued_on' => now()->subMonths(2)->toDateString(),
            'expires_on' => now()->addMonths(10)->toDateString(),
        ], $overrides);
    }

    // ====================================================================
    // RECORDING
    // ====================================================================

    #[Test]
    public function an_admin_can_record_a_document(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.document_type', 'INSURANCE');

        $this->assertDatabaseHas('bus_documents', [
            'bus_id' => $bus->id,
            'document_type' => 'INSURANCE',
        ]);
    }

    #[Test]
    public function a_driver_cannot_record_a_document(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->withoutDocuments()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($driver))->assertStatus(403);

        $this->assertDatabaseCount('bus_documents', 0);
    }

    #[Test]
    public function a_student_cannot_record_a_document(): void
    {
        $student = $this->createStudent();
        $bus = Bus::factory()->withoutDocuments()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function recording_a_document_requires_authentication(): void
    {
        $bus = Bus::factory()->withoutDocuments()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload())
            ->assertStatus(401);
    }

    #[Test]
    public function it_validates_the_document_payload(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['document_type', 'issued_on', 'expires_on']]);
    }

    #[Test]
    public function an_unknown_document_type_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents",
            $this->documentPayload(['document_type' => 'PARKING_PASS']),
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['document_type']]);
    }

    #[Test]
    public function an_expiry_before_the_issue_date_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload([
            'issued_on' => now()->toDateString(),
            'expires_on' => now()->subDay()->toDateString(),
        ]), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['expires_on']]);
    }

    #[Test]
    public function a_future_issue_date_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents",
            $this->documentPayload(['issued_on' => now()->addWeek()->toDateString()]),
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['issued_on']]);
    }

    #[Test]
    public function recording_a_document_is_audited(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'DOCUMENT_RECORDED',
            'table_name' => 'bus_documents',
        ]);
    }

    // ====================================================================
    // RENEWAL AND HISTORY
    // ====================================================================

    #[Test]
    public function a_renewal_supersedes_the_previous_document(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        $original = BusDocument::factory()->ofType(DocumentType::INSURANCE)
            ->create(['bus_id' => $bus->id]);

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))->assertStatus(201);

        // History is preserved, not overwritten: an investigation months later
        // must establish what cover was in force on the day.
        $this->assertNotNull($original->fresh()->superseded_by_id);
        $this->assertDatabaseCount('bus_documents', 2);
    }

    #[Test]
    public function only_the_current_document_is_listed_by_default(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        BusDocument::factory()->ofType(DocumentType::INSURANCE)->create(['bus_id' => $bus->id]);
        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))->assertStatus(201);

        $response = $this->getJson("/api/v1/buses/{$bus->id}/documents", $this->authHeader($admin))
            ->assertOk();

        $this->assertCount(1, $response->json('data.documents'));
    }

    #[Test]
    public function history_can_be_requested_explicitly(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        BusDocument::factory()->ofType(DocumentType::INSURANCE)->create(['bus_id' => $bus->id]);
        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))->assertStatus(201);

        $response = $this->getJson("/api/v1/buses/{$bus->id}/documents?include_history=1",
            $this->authHeader($admin))->assertOk();

        $this->assertCount(2, $response->json('data.documents'));
    }

    #[Test]
    public function a_superseded_document_cannot_be_deleted(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        $original = BusDocument::factory()->ofType(DocumentType::INSURANCE)
            ->create(['bus_id' => $bus->id]);

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))->assertStatus(201);

        $this->deleteJson("/api/v1/buses/{$bus->id}/documents/{$original->id}", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_current_document_can_be_deleted(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $document = $bus->documents()->first();

        $this->deleteJson("/api/v1/buses/{$bus->id}/documents/{$document->id}", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSoftDeleted('bus_documents', ['id' => $document->id]);
    }

    #[Test]
    public function a_document_cannot_be_reached_through_a_different_bus(): void
    {
        $admin = $this->createAdmin();
        $busA = Bus::factory()->create();
        $busB = Bus::factory()->create();
        $documentOnB = $busB->documents()->first();

        $this->putJson("/api/v1/buses/{$busA->id}/documents/{$documentOnB->id}",
            ['document_number' => 'HIJACKED'], $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Document not found for this bus.');
    }

    #[Test]
    public function the_document_type_cannot_be_changed_by_update(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();
        $document = $bus->documents()->where('document_type', DocumentType::INSURANCE->value)->first();

        $this->putJson("/api/v1/buses/{$bus->id}/documents/{$document->id}", [
            'document_number' => 'UPDATED-123',
            'document_type' => 'POLLUTION',
        ], $this->authHeader($admin))->assertOk();

        // Retyping a certificate would change which mandatory document the bus
        // is judged against.
        $this->assertSame(DocumentType::INSURANCE, $document->fresh()->document_type);
    }

    // ====================================================================
    // COMPLIANCE STATE
    // ====================================================================

    #[Test]
    public function a_fully_documented_bus_reports_compliant(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->getJson("/api/v1/buses/{$bus->id}/documents", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.compliance.is_compliant', true)
            ->assertJsonPath('data.compliance.missing_or_expired', []);
    }

    #[Test]
    public function a_bus_with_no_documents_reports_every_mandatory_type_missing(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        $response = $this->getJson("/api/v1/buses/{$bus->id}/documents", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.compliance.is_compliant', false);

        // A missing certificate is indistinguishable from no cover.
        $this->assertCount(3, $response->json('data.compliance.missing_or_expired'));
    }

    #[Test]
    public function a_document_expiring_today_is_still_valid(): void
    {
        $bus = Bus::factory()->withoutDocuments()->create();

        foreach (DocumentType::mandatory() as $type) {
            BusDocument::factory()->ofType($type)->expiringToday()->create(['bus_id' => $bus->id]);
        }

        // Cover runs to the end of its final day.
        $this->assertTrue($bus->fresh()->hasValidDocuments());
    }

    #[Test]
    public function a_document_that_expired_yesterday_is_not_valid(): void
    {
        $bus = Bus::factory()->withExpiredDocument(DocumentType::FITNESS)->create();

        $this->assertFalse($bus->fresh()->hasValidDocuments());
    }

    #[Test]
    public function a_lapsed_non_mandatory_document_does_not_block_the_bus(): void
    {
        $bus = Bus::factory()->create();
        BusDocument::factory()->ofType(DocumentType::POLLUTION)->expired()
            ->create(['bus_id' => $bus->id]);

        // Tracked and warned about, but not a bar to service.
        $this->assertTrue($bus->fresh()->hasValidDocuments());
    }

    // ====================================================================
    // ENFORCEMENT — BR-055
    // ====================================================================

    #[Test]
    public function a_bus_with_an_expired_document_cannot_be_assigned_to_a_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::INSURANCE)->create();

        $response = $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus",
            ['bus_id' => $bus->id], $this->authHeader($admin))->assertStatus(409);

        $this->assertContains('INSURANCE', $response->json('errors.missing_or_expired_documents'));
        $this->assertNull($driver->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function a_bus_with_no_documents_cannot_be_assigned_to_a_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->withoutDocuments()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus",
            ['bus_id' => $bus->id], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_fully_documented_bus_can_be_assigned(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus",
            ['bus_id' => $bus->id], $this->authHeader($admin))->assertOk();

        $this->assertSame($bus->id, $driver->driver->fresh()->assigned_bus_id);
    }

    #[Test]
    public function a_bus_with_an_expired_document_cannot_be_scheduled(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::PERMIT)->create();
        $driver = Driver::factory()->create();

        $this->postJson('/api/v1/schedules', [
            'route_id' => $route->id,
            'bus_id' => $bus->id,
            'driver_id' => $driver->id,
            'departure_time' => '08:00:00',
            'arrival_time' => '09:00:00',
            'day_of_week' => 'MONDAY',
            'frequency' => 'WEEKDAYS',
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertDatabaseCount('schedules', 0);
    }

    #[Test]
    public function recording_a_renewal_restores_assignability(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::INSURANCE)->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus",
            ['bus_id' => $bus->id], $this->authHeader($admin))->assertStatus(409);

        $this->postJson("/api/v1/buses/{$bus->id}/documents", $this->documentPayload(),
            $this->authHeader($admin))->assertStatus(201);

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus",
            ['bus_id' => $bus->id], $this->authHeader($admin))->assertOk();
    }

    // ====================================================================
    // EXPIRY BOARD
    // ====================================================================

    #[Test]
    public function expiring_documents_can_be_listed_across_the_fleet(): void
    {
        $admin = $this->createAdmin();

        $soon = Bus::factory()->withoutDocuments()->create();
        BusDocument::factory()->ofType(DocumentType::FITNESS)->expiringInDays(10)
            ->create(['bus_id' => $soon->id]);

        Bus::factory()->create(); // valid for months

        $response = $this->getJson('/api/v1/fleet/documents/expiring?days=30', $this->authHeader($admin))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    #[Test]
    public function an_already_expired_document_is_not_listed_as_expiring(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::FITNESS)->create();

        // Expired is a different, blocking condition — not an early warning.
        $response = $this->getJson('/api/v1/fleet/documents/expiring?days=30', $this->authHeader($admin))
            ->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    #[Test]
    public function the_expiry_window_is_validated(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/fleet/documents/expiring?days=9999', $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['days']]);
    }

    #[Test]
    public function a_driver_can_read_documents_but_not_change_them(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();
        $document = $bus->documents()->first();

        $this->getJson("/api/v1/buses/{$bus->id}/documents", $this->authHeader($driver))->assertOk();

        $this->putJson("/api/v1/buses/{$bus->id}/documents/{$document->id}",
            ['document_number' => 'CHANGED'], $this->authHeader($driver))->assertStatus(403);
    }
}
