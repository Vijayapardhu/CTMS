<?php

namespace Tests\Feature\Fleet;

use App\Enums\BusStatus;
use App\Enums\DocumentType;
use App\Enums\InspectionItem;
use App\Enums\InspectionOutcome;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-107, BR-108 — pre-trip vehicle inspection.
 *
 * The last point at which a fault is caught while a substitution is still
 * possible. A safety-critical failure must stop the bus, open a ticket, and
 * do both without anyone having to remember to.
 */
class VehicleInspectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete checklist with every item passing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function passingItems(): array
    {
        return array_map(fn (InspectionItem $item) => [
            'item' => $item->value,
            'passed' => true,
        ], InspectionItem::cases());
    }

    /**
     * A complete checklist with the named item failing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itemsFailing(InspectionItem $failing, bool $withPhoto = true, ?User $uploader = null): array
    {
        // A real upload, not an invented filename. Under the old contract this
        // helper satisfied the safety-critical photograph rule with a string
        // that pointed at nothing.
        $evidenceId = $withPhoto && $uploader !== null
            ? $this->inspectionEvidence($uploader)
            : null;

        return array_map(fn (InspectionItem $item) => $item === $failing
            ? [
                'item' => $item->value,
                'passed' => false,
                'notes' => 'Worn beyond the legal limit.',
                'evidence_id' => $evidenceId,
            ]
            : ['item' => $item->value, 'passed' => true],
            InspectionItem::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => $this->passingItems(),
            'odometer_reading' => 45000,
        ], $overrides);
    }

    // ====================================================================
    // CHECKLIST
    // ====================================================================

    #[Test]
    public function the_checklist_is_served_to_the_client(): void
    {
        $driver = $this->createDriver();

        $response = $this->getJson('/api/v1/inspections/checklist', $this->authHeader($driver))
            ->assertOk();

        // Served rather than hard-coded in the app, so adding an item does not
        // require a client release.
        $this->assertCount(count(InspectionItem::cases()), $response->json('data'));
        $this->assertArrayHasKey('safety_critical', $response->json('data.0'));
    }

    #[Test]
    public function the_checklist_requires_authentication(): void
    {
        $this->getJson('/api/v1/inspections/checklist')->assertStatus(401);
    }

    // ====================================================================
    // SUBMITTING — HAPPY PATH
    // ====================================================================

    #[Test]
    public function a_driver_can_submit_a_passing_inspection(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))
            ->assertStatus(201)
            ->assertJsonPath('data.outcome', 'PASSED');

        $this->assertDatabaseHas('vehicle_inspections', [
            'bus_id' => $bus->id,
            'driver_id' => $driver->driver->id,
            'outcome' => InspectionOutcome::PASSED->value,
        ]);
    }

    #[Test]
    public function every_checklist_verdict_is_recorded(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseCount('vehicle_inspection_items', count(InspectionItem::cases()));
    }

    #[Test]
    public function a_passing_inspection_opens_no_maintenance_ticket(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    #[Test]
    public function an_admin_can_record_an_inspection_for_a_named_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['driver_id' => $driver->driver->id]),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('vehicle_inspections', ['driver_id' => $driver->driver->id]);
    }

    #[Test]
    public function an_admin_must_name_the_driver(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['driver_id']]);
    }

    #[Test]
    public function submitting_an_inspection_is_audited(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $driver->id,
            'action' => 'INSPECTION_SUBMITTED',
            'table_name' => 'vehicle_inspections',
        ]);
    }

    // ====================================================================
    // NON-CRITICAL FAILURE — BR-108
    // ====================================================================

    #[Test]
    public function a_non_critical_failure_passes_with_defects(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::CLEANLINESS, withPhoto: false)]),
            $this->authHeader($driver))
            ->assertStatus(201)
            ->assertJsonPath('data.outcome', 'PASSED_WITH_DEFECTS');
    }

    #[Test]
    public function a_non_critical_failure_opens_a_ticket_but_leaves_the_bus_in_service(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::HORN, withPhoto: false)]),
            $this->authHeader($driver))->assertStatus(201);

        // A dirty cabin is a problem, not a hazard.
        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
        $this->assertDatabaseHas('maintenance_tickets', [
            'bus_id' => $bus->id,
            'status' => 'OPEN',
            'priority' => 'MEDIUM',
        ]);
    }

    #[Test]
    public function a_bus_with_only_non_critical_defects_is_still_cleared(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::WIPERS, withPhoto: false)]),
            $this->authHeader($driver))->assertStatus(201);

        $this->getJson("/api/v1/buses/{$bus->id}/service-readiness", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', true);
    }

    // ====================================================================
    // SAFETY-CRITICAL FAILURE — BR-108
    // ====================================================================

    #[Test]
    public function a_safety_critical_failure_fails_the_inspection(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::BRAKES, uploader: $driver)]),
            $this->authHeader($driver))
            ->assertStatus(201)
            ->assertJsonPath('data.outcome', 'FAILED');
    }

    #[Test]
    public function a_safety_critical_failure_takes_the_bus_out_of_service(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::BRAKES, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        $this->assertSame(BusStatus::MAINTENANCE, $bus->fresh()->status);
    }

    #[Test]
    public function a_safety_critical_failure_opens_an_urgent_ticket(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::TYRES, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        $this->assertDatabaseHas('maintenance_tickets', [
            'bus_id' => $bus->id,
            'status' => 'OPEN',
            'priority' => 'URGENT',
        ]);
    }

    #[Test]
    public function the_ticket_is_linked_to_the_inspection(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $response = $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::STEERING, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        $this->assertNotNull($response->json('data.maintenance_ticket_id'));
    }

    #[Test]
    public function the_bus_status_change_is_audited_with_its_reason(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::DOORS, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        $log = AuditLog::where('action', 'BUS_STATUS_CHANGED')->first();

        $this->assertNotNull($log);
        $this->assertSame('MAINTENANCE', $log->new_values['status']);
        $this->assertSame('Failed pre-trip inspection', $log->new_values['reason']);
    }

    #[Test]
    public function every_safety_critical_item_blocks_the_bus(): void
    {
        foreach (InspectionItem::safetyCritical() as $item) {
            $driver = $this->createDriver();
            $bus = Bus::factory()->create();

            $this->postJson("/api/v1/buses/{$bus->id}/inspections",
                $this->payload(['items' => $this->itemsFailing($item, uploader: $driver)]),
                $this->authHeader($driver))
                ->assertStatus(201)
                ->assertJsonPath('data.outcome', 'FAILED');
        }
    }

    // ====================================================================
    // SERVICE READINESS — BR-107
    // ====================================================================

    #[Test]
    public function a_bus_with_no_inspection_today_is_not_cleared(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $response = $this->getJson("/api/v1/buses/{$bus->id}/service-readiness",
            $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', false);

        // A disabled Start Trip button with no stated reason is a support call.
        $this->assertContains(
            'No pre-trip inspection has been completed today.',
            $response->json('data.reasons'),
        );
    }

    #[Test]
    public function a_bus_inspected_today_is_cleared(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);

        $this->getJson("/api/v1/buses/{$bus->id}/service-readiness", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', true)
            ->assertJsonPath('data.reasons', []);
    }

    #[Test]
    public function yesterdays_inspection_does_not_clear_the_bus_today(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->travel(-1)->days();
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);
        $this->travelBack();

        // The check is per day, every day. That is the point of it.
        $this->getJson("/api/v1/buses/{$bus->id}/service-readiness", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', false);
    }

    #[Test]
    public function a_failed_inspection_does_not_clear_the_bus(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::LIGHTS, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        $response = $this->getJson("/api/v1/buses/{$bus->id}/service-readiness",
            $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', false);

        $this->assertStringContainsString('Head, tail and indicator lights',
            implode(' ', $response->json('data.reasons')));
    }

    #[Test]
    public function an_expired_document_is_reported_in_readiness(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::INSURANCE)->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);

        $response = $this->getJson("/api/v1/buses/{$bus->id}/service-readiness",
            $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', false);

        $this->assertStringContainsString('Insurance', implode(' ', $response->json('data.reasons')));
    }

    #[Test]
    public function readiness_reports_every_blocking_reason_at_once(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->withoutDocuments()->create();

        // Missing all three documents and no inspection: four reasons.
        $response = $this->getJson("/api/v1/buses/{$bus->id}/service-readiness",
            $this->authHeader($driver))->assertOk();

        $this->assertCount(4, $response->json('data.reasons'));
    }

    #[Test]
    public function a_re_inspection_after_repair_clears_the_bus(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::BRAKES, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        // The bus must come back through MAINTENANCE (BR-051).
        $this->patchJson("/api/v1/buses/{$bus->id}/status", ['status' => 'AVAILABLE'],
            $this->authHeader($admin))->assertOk();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['odometer_reading' => 45010]),
            $this->authHeader($driver))->assertStatus(201);

        $this->getJson("/api/v1/buses/{$bus->id}/service-readiness", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.cleared', true);
    }

    // ====================================================================
    // VALIDATION
    // ====================================================================

    #[Test]
    public function an_incomplete_checklist_is_rejected(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $partial = array_slice($this->passingItems(), 0, 5);

        // A partially completed inspection is worse than none — it looks like
        // diligence.
        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $partial]), $this->authHeader($driver))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['items']]);
    }

    #[Test]
    public function a_failed_item_requires_an_explanation(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = array_map(fn (InspectionItem $item) => $item === InspectionItem::CLEANLINESS
            ? ['item' => $item->value, 'passed' => false]
            : ['item' => $item->value, 'passed' => true],
            InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $items]), $this->authHeader($driver))
            ->assertStatus(422);
    }

    #[Test]
    public function a_safety_critical_failure_requires_a_photograph(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        // A bus taken off the road on an unexplained tick is not a defensible
        // record.
        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::BRAKES, withPhoto: false)]),
            $this->authHeader($driver))
            ->assertStatus(422);

        $this->assertSame(BusStatus::AVAILABLE, $bus->fresh()->status);
    }

    #[Test]
    public function an_unknown_checklist_item_is_rejected(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = $this->passingItems();
        $items[0]['item'] = 'ASHTRAY';

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $items]), $this->authHeader($driver))
            ->assertStatus(422);
    }

    #[Test]
    public function the_odometer_cannot_go_backwards(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['odometer_reading' => 50000]), $this->authHeader($driver))
            ->assertStatus(201);

        $this->travel(1)->days();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['odometer_reading' => 49000]), $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function the_outcome_cannot_be_dictated_by_the_client(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        // A driver must not be able to submit failures marked as a pass.
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload([
            'items' => $this->itemsFailing(InspectionItem::BRAKES, uploader: $driver),
            'outcome' => 'PASSED',
        ]), $this->authHeader($driver))
            ->assertStatus(201)
            ->assertJsonPath('data.outcome', 'FAILED');
    }

    // ====================================================================
    // AUTHORIZATION
    // ====================================================================

    #[Test]
    public function a_student_cannot_submit_an_inspection(): void
    {
        $student = $this->createStudent();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($student))->assertStatus(403);

        $this->assertDatabaseCount('vehicle_inspections', 0);
    }

    #[Test]
    public function submitting_an_inspection_requires_authentication(): void
    {
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload())
            ->assertStatus(401);
    }

    #[Test]
    public function a_driver_can_read_their_own_inspection(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $id = $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->json('data.id');

        $this->getJson("/api/v1/inspections/{$id}", $this->authHeader($driver))->assertOk();
    }

    #[Test]
    public function a_driver_cannot_read_another_drivers_inspection(): void
    {
        $alice = $this->createDriver();
        $bob = $this->createDriver();
        $bus = Bus::factory()->create();

        $id = $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($alice))->json('data.id');

        $this->getJson("/api/v1/inspections/{$id}", $this->authHeader($bob))->assertStatus(403);
    }

    #[Test]
    public function an_admin_can_read_any_inspection(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $id = $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->json('data.id');

        $this->getJson("/api/v1/inspections/{$id}", $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function reading_an_unknown_inspection_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->getJson('/api/v1/inspections/019fd73c-0000-7000-8000-000000000000',
            $this->authHeader($admin))->assertStatus(404);
    }

    #[Test]
    public function submitting_for_an_unknown_bus_returns_404(): void
    {
        $driver = $this->createDriver();

        $this->postJson('/api/v1/buses/019fd73c-0000-7000-8000-000000000000/inspections',
            $this->payload(), $this->authHeader($driver))->assertStatus(404);
    }

    // ====================================================================
    // HISTORY
    // ====================================================================

    #[Test]
    public function inspection_history_can_be_listed_for_a_bus(): void
    {
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", $this->payload(),
            $this->authHeader($driver))->assertStatus(201);

        $this->getJson("/api/v1/buses/{$bus->id}/inspections", $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    #[Test]
    public function inspection_history_can_be_filtered_by_outcome(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/buses/{$bus->id}/inspections",
            $this->payload(['items' => $this->itemsFailing(InspectionItem::TYRES, uploader: $driver)]),
            $this->authHeader($driver))->assertStatus(201);

        $this->getJson("/api/v1/buses/{$bus->id}/inspections?outcome=FAILED", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson("/api/v1/buses/{$bus->id}/inspections?outcome=PASSED", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }

    #[Test]
    public function an_unknown_outcome_filter_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->create();

        $this->getJson("/api/v1/buses/{$bus->id}/inspections?outcome=MAYBE", $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['outcome']]);
    }
}
