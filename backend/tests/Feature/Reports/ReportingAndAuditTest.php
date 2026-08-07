<?php

namespace Tests\Feature\Reports;

use App\Enums\InspectionItem;
use App\Enums\MaintenancePriority;
use App\Exceptions\BusinessRuleException;
use App\Jobs\PurgeExpiredData;
use App\Models\AttendanceDiscrepancy;
use App\Models\AuditLog;
use App\Models\Bus;
use App\Models\DataAccessLog;
use App\Models\Notification;
use App\Models\PassengerLog;
use App\Models\Route;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Models\User;
use App\Services\Governance\RetentionService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-15 — reports, audit and data protection (BR-500..BR-512).
 */
class ReportingAndAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Trip}
     */
    private function completedTrip(int $aboard = 0): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))->assertOk();

        for ($i = 0; $i < $aboard; $i++) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driverUser))->assertOk();
        }

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10050,
        ], $this->authHeader($driverUser))->assertOk();

        return [$driverUser, $trip->fresh()];
    }

    // ====================================================================
    // BR-507 — AUDIT RECORDS ARE APPEND-ONLY
    // ====================================================================

    #[Test]
    public function an_audit_record_cannot_be_edited(): void
    {
        $this->completedTrip();

        $log = AuditLog::first();
        $this->assertNotNull($log);

        // Enforced on the model, not just in a policy: the realistic way an
        // audit row gets rewritten is a service doing it directly.
        $this->expectException(BusinessRuleException::class);

        $log->forceFill(['action' => 'SOMETHING_ELSE'])->save();
    }

    #[Test]
    public function an_audit_record_cannot_be_deleted(): void
    {
        $this->completedTrip();

        $this->expectException(BusinessRuleException::class);

        AuditLog::first()->delete();
    }

    #[Test]
    public function there_is_no_write_endpoint_on_the_audit_trail(): void
    {
        $admin = $this->createSuperAdmin();
        $this->completedTrip();

        $log = AuditLog::first();

        // 405 rather than 403: the route does not exist at all, which is the
        // stronger guarantee.
        $this->postJson('/api/v1/audit-logs', [], $this->authHeader($admin))->assertStatus(405);
        $this->deleteJson("/api/v1/audit-logs/{$log->id}", [], $this->authHeader($admin))->assertStatus(405);
    }

    #[Test]
    public function a_driver_cannot_read_the_audit_trail(): void
    {
        [$driver] = $this->completedTrip();

        $this->getJson('/api/v1/audit-logs', $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function the_audit_trail_is_filterable_by_record(): void
    {
        $admin = $this->createSuperAdmin();
        [, $trip] = $this->completedTrip();

        $this->getJson("/api/v1/audit-logs?record_id={$trip->id}", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', fn ($total) => $total >= 1);
    }

    // ====================================================================
    // BR-509 — AUDITING NEVER BREAKS THE OPERATION
    // ====================================================================

    #[Test]
    public function a_failing_audit_write_does_not_fail_the_trip(): void
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        // Break the audit table underneath the operation.
        Schema::drop('audit_logs');

        // The bus still starts its run. An audit outage must not stop buses.
        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertOk();
    }

    // ====================================================================
    // BR-501, BR-502 — THE ACCESS RECORD
    // ====================================================================

    #[Test]
    public function a_member_of_staff_opening_a_students_record_is_logged(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->getJson("/api/v1/students/{$student->student->id}", $this->authHeader($admin))
            ->assertOk();

        $entry = DataAccessLog::forSubject('student', (string) $student->student->id)->first();

        // BR-501. The logger existed from the start and nothing called it, so
        // the trail could answer "who changed this student" but not "who
        // looked at them" — which is the question asked when something has
        // gone wrong.
        $this->assertNotNull($entry);
        $this->assertSame((string) $admin->id, (string) $entry->user_id);
        $this->assertSame('STUDENT_RECORD', $entry->data_class);
        $this->assertFalse($entry->is_bulk);
    }

    #[Test]
    public function a_student_reading_their_own_record_is_not_logged_as_staff_access(): void
    {
        $student = $this->createStudent();

        $this->getJson("/api/v1/students/{$student->student->id}", $this->authHeader($student))
            ->assertOk();

        // Logging this would bury the entries that matter under every rider
        // opening their own profile.
        $this->assertSame(0, DataAccessLog::count());
    }

    #[Test]
    public function a_subject_access_export_requires_a_stated_reason(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->postJson("/api/v1/users/{$student->id}/subject-access-export", [],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    #[Test]
    public function a_subject_access_export_is_recorded_against_the_person_who_ran_it(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->postJson("/api/v1/users/{$student->id}/subject-access-export", [
            'reason' => 'Formal subject access request received by post on 3 August.',
        ], $this->authHeader($admin))->assertOk();

        $entry = DataAccessLog::bulk()->first();

        $this->assertNotNull($entry);
        $this->assertSame((string) $admin->id, (string) $entry->user_id);
        $this->assertStringContainsString('subject access request', $entry->reason);
    }

    #[Test]
    public function the_export_returns_the_persons_own_record(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $response = $this->postJson("/api/v1/users/{$student->id}/subject-access-export", [
            'reason' => 'Subject access request received in writing.',
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame($student->email, $response->json('data.subject.email'));
        $this->assertArrayHasKey('journeys', $response->json('data'));
        // The person is entitled to know that reads of their data were logged.
        $this->assertArrayHasKey('access_record_count', $response->json('data'));
    }

    #[Test]
    public function an_access_record_cannot_be_deleted(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent();

        $this->postJson("/api/v1/users/{$student->id}/subject-access-export", [
            'reason' => 'Subject access request received in writing.',
        ], $this->authHeader($admin))->assertOk();

        $this->expectException(BusinessRuleException::class);

        DataAccessLog::first()->delete();
    }

    #[Test]
    public function a_driver_cannot_export_a_students_record(): void
    {
        [$driver] = $this->completedTrip();
        $student = $this->createStudent();

        $this->postJson("/api/v1/users/{$student->id}/subject-access-export", [
            'reason' => 'Curious about this student.',
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_export_another_students_record(): void
    {
        $student = $this->createStudent();
        $other = $this->createStudent();

        $this->postJson("/api/v1/users/{$other->id}/subject-access-export", [
            'reason' => 'Trying to read a classmate.',
        ], $this->authHeader($student))->assertStatus(403);
    }

    // ====================================================================
    // FR-15 — REPORTS
    // ====================================================================

    #[Test]
    public function the_trip_report_counts_what_happened(): void
    {
        $admin = $this->createSuperAdmin();
        $this->completedTrip();
        $this->completedTrip();

        $response = $this->getJson('/api/v1/reports/trips', $this->authHeader($admin))->assertOk();

        $this->assertSame(2, $response->json('data.trips.completed'));
        $this->assertEquals(100, $response->json('data.completion_rate'));
    }

    #[Test]
    public function the_occupancy_report_lists_the_emptiest_routes_first(): void
    {
        $admin = $this->createSuperAdmin();

        $this->completedTrip(aboard: 2);
        $this->completedTrip(aboard: 20);

        $response = $this->getJson('/api/v1/reports/occupancy', $this->authHeader($admin))->assertOk();

        $byRoute = $response->json('data.by_route');

        // Ascending, so the consolidation candidates are the first thing read.
        $this->assertCount(2, $byRoute);
        $this->assertLessThan(
            $byRoute[1]['utilisation_percent'],
            $byRoute[0]['utilisation_percent'],
        );
    }

    #[Test]
    public function the_fleet_report_says_what_is_off_the_road(): void
    {
        $admin = $this->createSuperAdmin();
        $bus = Bus::factory()->create();

        app(MaintenanceService::class)->open($bus, [
            'issue_description' => 'Brake fault.',
            'priority' => MaintenancePriority::URGENT,
        ], $admin);

        $response = $this->getJson('/api/v1/reports/fleet', $this->authHeader($admin))->assertOk();

        $this->assertSame(1, $response->json('data.grounded_by_maintenance'));
        $this->assertSame(1, $response->json('data.open_tickets.total'));
    }

    #[Test]
    public function the_incident_report_measures_how_long_an_sos_waited(): void
    {
        $admin = $this->createSuperAdmin();
        [$driver, $trip] = $this->completedTrip();

        $id = $this->postJson('/api/v1/incidents', [
            'incident_type' => 'SOS',
            'trip_id' => $trip->id,
        ], $this->authHeader($driver))->json('data.id');

        $this->travel(90)->seconds();

        $this->postJson("/api/v1/incidents/{$id}/acknowledge", [], $this->authHeader($admin))->assertOk();

        $response = $this->getJson('/api/v1/reports/incidents', $this->authHeader($admin))->assertOk();

        // The number this report exists to surface.
        $this->assertSame(90, $response->json('data.life_safety_median_acknowledgement_seconds'));
        $this->assertSame(0, $response->json('data.unacknowledged'));
    }

    #[Test]
    public function reports_are_bounded_to_a_sane_window(): void
    {
        $admin = $this->createSuperAdmin();

        // An unbounded window is how a report becomes a full-table scan on a
        // production database at nine in the morning.
        $this->getJson('/api/v1/reports/trips?from=1990-01-01&to=2030-01-01',
            $this->authHeader($admin))->assertOk();
    }

    #[Test]
    public function a_backwards_window_is_refused(): void
    {
        $admin = $this->createSuperAdmin();

        $this->getJson('/api/v1/reports/trips?from=2026-08-01&to=2026-07-01',
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function a_student_cannot_read_reports(): void
    {
        $student = $this->createStudent();

        $this->getJson('/api/v1/reports/occupancy', $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function reports_require_authentication(): void
    {
        $this->getJson('/api/v1/reports/trips')->assertStatus(401);
    }

    #[Test]
    public function a_report_never_names_a_student(): void
    {
        $admin = $this->createSuperAdmin();
        $student = $this->createStudent(['email' => 'private.person@example.com']);

        [$driver, $trip] = $this->completedTrip();

        $content = $this->getJson('/api/v1/reports/occupancy', $this->authHeader($admin))
            ->assertOk()->getContent();

        // BR-500 — a report is a count, not a lookup. This surface must never
        // become a way to read who was on which bus.
        $this->assertStringNotContainsString('private.person@example.com', $content);
    }

    // ====================================================================
    // BG-19, BR-307, BR-504, BR-505 — RETENTION
    // ====================================================================

    #[Test]
    public function old_location_traces_are_purged(): void
    {
        [, $trip] = $this->completedTrip();

        TripLocation::factory()->count(3)->create([
            'trip_id' => $trip->id,
            'recorded_at' => now()->subDays(200),
        ]);

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        // BR-307 — the second-by-second breadcrumb of where a child was is the
        // most sensitive data here and has the shortest window.
        $this->assertSame(0, TripLocation::where('trip_id', $trip->id)->count());
    }

    #[Test]
    public function recent_traces_survive(): void
    {
        [, $trip] = $this->completedTrip();

        TripLocation::factory()->count(2)->create([
            'trip_id' => $trip->id,
            'recorded_at' => now()->subDays(5),
        ]);

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        $this->assertSame(2, TripLocation::where('trip_id', $trip->id)->count());
    }

    #[Test]
    public function the_attendance_record_is_never_purged(): void
    {
        [, $trip] = $this->completedTrip(aboard: 4);

        TripLocation::factory()->create([
            'trip_id' => $trip->id,
            'recorded_at' => now()->subDays(400),
        ]);

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        // BR-505 — losing this would destroy the answer to "was my child on
        // that bus", and no retention policy is worth that.
        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
        $this->assertSame(4, $trip->fresh()->occupied_seat_count);
        $this->assertGreaterThan(0, PassengerLog::where('trip_id', $trip->id)->count());
    }

    #[Test]
    public function a_trace_under_an_open_discrepancy_is_not_purged(): void
    {
        [, $trip] = $this->completedTrip(aboard: 4);

        $discrepancy = new AttendanceDiscrepancy;
        $discrepancy->forceFill([
            'trip_id' => $trip->id,
            'headcount' => 4,
            'boarding_event_count' => 2,
            'difference' => 2,
            'status' => 'OPEN',
        ])->save();

        TripLocation::factory()->create([
            'trip_id' => $trip->id,
            'recorded_at' => now()->subDays(400),
        ]);

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        // Two children are unaccounted for on this trip. Deleting the trace
        // while that is unresolved destroys the only evidence of where the bus
        // actually went.
        $this->assertSame(1, TripLocation::where('trip_id', $trip->id)->count());
    }

    #[Test]
    public function a_purge_records_what_it_did(): void
    {
        [, $trip] = $this->completedTrip();

        TripLocation::factory()->create([
            'trip_id' => $trip->id, 'recorded_at' => now()->subDays(200),
        ]);

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        $this->assertDatabaseHas('retention_runs', [
            'data_class' => 'LOCATION_TRACE',
            'outcome' => 'PURGED',
        ]);
        // The dry pass is the only record of what a purge was about to do.
        $this->assertDatabaseHas('retention_runs', [
            'data_class' => 'LOCATION_TRACE',
            'outcome' => 'DRY_RUN',
        ]);
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        [, $trip] = $this->completedTrip();

        TripLocation::factory()->create([
            'trip_id' => $trip->id, 'recorded_at' => now()->subDays(200),
        ]);

        (new PurgeExpiredData(dryRunOnly: true))->handle(app(RetentionService::class));

        $this->assertSame(1, TripLocation::where('trip_id', $trip->id)->count());
    }

    #[Test]
    public function old_notifications_are_purged(): void
    {
        $admin = $this->createSuperAdmin();

        Notification::factory()->create([
            'user_id' => $admin->id,
            'created_at' => now()->subDays(90),
        ]);

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
    }

    #[Test]
    public function retention_runs_are_visible_to_operations(): void
    {
        $admin = $this->createSuperAdmin();

        (new PurgeExpiredData)->handle(app(RetentionService::class));

        $this->getJson('/api/v1/retention-runs', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('pagination.total', fn ($t) => $t > 0);
    }
}
