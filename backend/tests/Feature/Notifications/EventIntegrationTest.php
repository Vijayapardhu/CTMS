<?php

namespace Tests\Feature\Notifications;

use App\Enums\DocumentType;
use App\Enums\InspectionItem;
use App\Enums\NotificationCategory;
use App\Enums\NotificationPriority;
use App\Jobs\ScanExpiringDocuments;
use App\Models\Bus;
use App\Models\BusDocument;
use App\Models\Notification;
use App\Models\Route;
use App\Services\Notifications\NotificationDispatcher;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The L2 proof: modules 1, 2 and 3 emit the notifications the blueprint
 * requires of them, through domain events, without containing any delivery
 * logic themselves.
 *
 * Each test drives a real API endpoint and asserts a notification landed —
 * the whole chain from service, to event, to listener, to dispatcher, to
 * delivery record.
 */
class EventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function notificationsFor(string $userId, string $eventKey): Collection
    {
        return Notification::where('user_id', $userId)->where('event_key', $eventKey)->get();
    }

    // ====================================================================
    // MODULE 3 — TRANSPORT ASSIGNMENT (N-25, N-26, BR-213)
    // ====================================================================

    #[Test]
    public function assigning_transport_notifies_the_student(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $stop = $route->stops()->first();
        $student = $this->createStudent();

        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $stop->id,
        ], $this->authHeader($admin))->assertOk();

        $notifications = $this->notificationsFor($student->id, 'student.transport.assigned');

        $this->assertCount(1, $notifications);
        $this->assertSame(NotificationCategory::TRANSPORT, $notifications->first()->category);
        $this->assertStringContainsString($route->route_name, $notifications->first()->body);
        $this->assertStringContainsString($stop->stop_name, $notifications->first()->body);
    }

    #[Test]
    public function changing_a_route_notifies_the_students_riding_it(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $student->student->forceFill(['route_id' => $route->id])->save();

        $this->putJson("/api/v1/routes/{$route->id}", [
            'route_name' => 'Renamed Morning Service',
        ], $this->authHeader($admin))->assertOk();

        // BR-213 proper. This was previously credited to the transport
        // *assignment* test, which covers a different act: the RouteChanged
        // event existed from the start and nothing ever dispatched it, so a
        // route could be re-timed and every rider on it would find out by
        // standing at a stop.
        $notifications = $this->notificationsFor($student->id, 'route.changed');

        $this->assertCount(1, $notifications);
        $this->assertSame(NotificationCategory::TRANSPORT, $notifications->first()->category);
        $this->assertStringContainsString('Renamed Morning Service', $notifications->first()->title);
        $this->assertContains('route_name', $notifications->first()->data['changed_fields']);
    }

    #[Test]
    public function a_route_update_that_changes_nothing_notifies_nobody(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $student->student->forceFill(['route_id' => $route->id])->save();

        // Re-submitting the same values is not a change, and telling riders
        // their route changed when it did not is how people learn to ignore
        // the alerts that matter.
        $this->putJson("/api/v1/routes/{$route->id}", [
            'route_name' => $route->route_name,
        ], $this->authHeader($admin))->assertOk();

        $this->assertCount(0, $this->notificationsFor($student->id, 'route.changed'));
    }

    #[Test]
    public function the_notification_carries_a_payload_for_deep_linking(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $stop = $route->stops()->first();
        $student = $this->createStudent();

        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $stop->id,
        ], $this->authHeader($admin))->assertOk();

        $data = $this->notificationsFor($student->id, 'student.transport.assigned')->first()->data;

        $this->assertSame((string) $route->id, $data['route_id']);
        $this->assertSame((string) $stop->id, $data['pickup_stop_id']);
    }

    #[Test]
    public function reassigning_transport_notifies_as_a_change(): void
    {
        $admin = $this->createAdmin();
        $first = Route::factory()->withStops()->create();
        $second = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        foreach ([$first, $second] as $route) {
            $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
                'route_id' => $route->id,
                'pickup_stop_id' => $route->stops()->first()->id,
            ], $this->authHeader($admin))->assertOk();
        }

        $this->assertCount(1, $this->notificationsFor($student->id, 'student.transport.assigned'));
        $this->assertCount(1, $this->notificationsFor($student->id, 'student.transport.changed'));
    }

    #[Test]
    public function clearing_transport_notifies_the_student(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $this->authHeader($admin))->assertOk();

        $this->deleteJson("/api/v1/students/{$student->student->id}/assign-transport", [],
            $this->authHeader($admin))->assertOk();

        // A student turning up for a bus that is not coming is the failure
        // this message prevents.
        $this->assertCount(1, $this->notificationsFor($student->id, 'student.transport.cleared'));
    }

    #[Test]
    public function clearing_transport_a_student_never_had_notifies_nobody(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        $this->deleteJson("/api/v1/students/{$student->student->id}/assign-transport", [],
            $this->authHeader($admin))->assertOk();

        $this->assertCount(0, $this->notificationsFor($student->id, 'student.transport.cleared'));
    }

    #[Test]
    public function a_failed_assignment_notifies_nobody(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $otherRoute = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        // Stop belongs to a different route — rejected.
        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $otherRoute->stops()->first()->id,
        ], $this->authHeader($admin))->assertStatus(409);

        $this->assertDatabaseCount('notifications', 0);
    }

    // ====================================================================
    // MODULE 2 — FLEET (N-16, N-20, N-22, N-23)
    // ====================================================================

    #[Test]
    public function assigning_a_bus_notifies_the_driver(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $this->postJson("/api/v1/drivers/{$driver->driver->id}/assign-bus",
            ['bus_id' => $bus->id], $this->authHeader($admin))->assertOk();

        $notifications = $this->notificationsFor($driver->id, 'driver.bus.assigned');

        $this->assertCount(1, $notifications);
        $this->assertStringContainsString($bus->registration_number, $notifications->first()->body);
    }

    #[Test]
    public function a_failed_inspection_notifies_operations_critically(): void
    {
        $admin = $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = array_map(fn (InspectionItem $item) => $item === InspectionItem::BRAKES
            ? [
                'item' => $item->value,
                'passed' => false,
                'notes' => 'Pedal travel excessive.',
                'evidence_id' => $this->inspectionEvidence($driver),
            ]
            : ['item' => $item->value, 'passed' => true],
            InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items,
            'odometer_reading' => 12000,
        ], $this->authHeader($driver))->assertStatus(201);

        $notifications = $this->notificationsFor($admin->id, 'inspection.failed');

        $this->assertCount(1, $notifications);
        // Operations need this while there is still time to substitute.
        $this->assertSame(NotificationPriority::CRITICAL, $notifications->first()->priority);
        $this->assertStringContainsString('Brakes', $notifications->first()->body);
    }

    #[Test]
    public function a_passing_inspection_notifies_nobody(): void
    {
        $this->createAdmin();
        $driver = $this->createDriver();
        $bus = Bus::factory()->create();

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items,
            'odometer_reading' => 12000,
        ], $this->authHeader($driver))->assertStatus(201);

        $this->assertSame(0, Notification::where('event_key', 'inspection.failed')->count());
    }

    // ====================================================================
    // BG-14 — DOCUMENT EXPIRY SCAN (N-22, N-23)
    // ====================================================================

    #[Test]
    public function the_expiry_scan_warns_before_a_document_lapses(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        BusDocument::factory()->ofType(DocumentType::INSURANCE)->expiringInDays(7)
            ->create(['bus_id' => $bus->id]);

        (new ScanExpiringDocuments)->handle();

        $notifications = $this->notificationsFor($admin->id, 'fleet.document.expiring');

        $this->assertCount(1, $notifications);
        $this->assertStringContainsString($bus->registration_number, $notifications->first()->title);
    }

    #[Test]
    public function the_expiry_scan_reports_a_lapsed_document_as_critical(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withExpiredDocument(DocumentType::FITNESS)->create();

        (new ScanExpiringDocuments)->handle();

        $notifications = $this->notificationsFor($admin->id, 'fleet.document.expired');

        $this->assertCount(1, $notifications);
        // The bus stops being usable whether or not anybody noticed.
        $this->assertSame(NotificationPriority::CRITICAL, $notifications->first()->priority);
    }

    #[Test]
    public function running_the_scan_twice_in_a_day_notifies_once(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        BusDocument::factory()->ofType(DocumentType::PERMIT)->expiringInDays(14)
            ->create(['bus_id' => $bus->id]);

        (new ScanExpiringDocuments)->handle();
        (new ScanExpiringDocuments)->handle();

        // BR-405 — idempotent by construction.
        $this->assertCount(1, $this->notificationsFor($admin->id, 'fleet.document.expiring'));
    }

    #[Test]
    public function the_scan_ignores_documents_outside_the_warning_thresholds(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        BusDocument::factory()->ofType(DocumentType::INSURANCE)->expiringInDays(45)
            ->create(['bus_id' => $bus->id]);

        (new ScanExpiringDocuments)->handle();

        $this->assertCount(0, $this->notificationsFor($admin->id, 'fleet.document.expiring'));
    }

    #[Test]
    public function the_scan_ignores_non_mandatory_documents(): void
    {
        $admin = $this->createAdmin();
        $bus = Bus::factory()->withoutDocuments()->create();

        BusDocument::factory()->ofType(DocumentType::POLLUTION)->expiringInDays(7)
            ->create(['bus_id' => $bus->id]);

        (new ScanExpiringDocuments)->handle();

        // A lapsed pollution certificate does not take the bus off the road.
        $this->assertCount(0, $this->notificationsFor($admin->id, 'fleet.document.expiring'));
    }

    // ====================================================================
    // MODULE 1 — ACCOUNT (N-38, N-39)
    // ====================================================================

    #[Test]
    public function changing_a_password_notifies_the_account_holder(): void
    {
        $user = $this->createStudent();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'An0ther!Str0ng',
            'password_confirmation' => 'An0ther!Str0ng',
        ], $this->authHeader($user))->assertOk();

        $notifications = $this->notificationsFor($user->id, 'account.password.changed');

        $this->assertCount(1, $notifications);
        // If it was not them, this is how they find out.
        $this->assertSame(NotificationPriority::CRITICAL, $notifications->first()->priority);
    }

    #[Test]
    public function deactivating_an_account_notifies_the_holder(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->patchJson("/api/v1/users/{$student->id}/status", ['is_active' => false],
            $this->authHeader($admin))->assertOk();

        // Published before the tokens die, so the recipient is still entitled
        // at dispatch (BR-401).
        $this->assertCount(1, $this->notificationsFor($student->id, 'account.deactivated'));
    }

    #[Test]
    public function reactivating_an_account_notifies_nobody(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent(['is_active' => false]);

        $this->patchJson("/api/v1/users/{$student->id}/status", ['is_active' => true],
            $this->authHeader($admin))->assertOk();

        $this->assertCount(0, $this->notificationsFor($student->id, 'account.deactivated'));
    }

    // ====================================================================
    // THE BOUNDARY
    // ====================================================================

    #[Test]
    public function a_notification_failure_never_breaks_the_operation(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();

        // Force the delivery layer to blow up.
        $this->app->bind(NotificationDispatcher::class, function () {
            throw new \RuntimeException('Notification platform is down');
        });

        // BR-408 — the assignment must still succeed.
        $this->postJson("/api/v1/students/{$student->student->id}/assign-transport", [
            'route_id' => $route->id,
            'pickup_stop_id' => $route->stops()->first()->id,
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($route->id, $student->student->fresh()->route_id);
    }
}
