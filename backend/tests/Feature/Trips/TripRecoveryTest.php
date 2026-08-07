<?php

namespace Tests\Feature\Trips;

use App\Enums\InspectionItem;
use App\Enums\TripStatus;
use App\Jobs\ReconcileAttendance;
use App\Models\AttendanceDiscrepancy;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Trip;
use App\Models\TripCorrection;
use App\Models\User;
use App\Services\Trips\TripRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Trip recovery — BR-105, BR-258, BR-266, BR-267.
 *
 * What these rules have in common is a refusal to let the system quietly
 * rewrite what it recorded. A correction sits beside the original. A
 * disagreement stays a disagreement until a human explains it.
 */
class TripRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Trip, 2: Bus}
     */
    private function runningTrip(int $aboard = 0): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(40)->create();

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

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

        return [$driverUser, $trip->fresh(), $bus->fresh()];
    }

    private function completedTrip(int $aboard = 0): array
    {
        [$driver, $trip, $bus] = $this->runningTrip($aboard);

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10050,
        ], $this->authHeader($driver))->assertOk();

        return [$driver, $trip->fresh(), $bus->fresh()];
    }

    // ====================================================================
    // BR-105 — ONE ACTIVE TRIP PER DRIVER
    // ====================================================================

    #[Test]
    public function a_driver_cannot_run_two_trips_at_once(): void
    {
        [$driver, $first] = $this->runningTrip();

        $second = Trip::factory()->departingNow()->create([
            'bus_id' => Bus::factory()->withCapacity(40)->create()->id,
            'driver_id' => $driver->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        // One person cannot be driving two buses, and a system that believes
        // they can will report both as staffed.
        $this->postJson("/api/v1/trips/{$second->id}/start", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function a_driver_is_free_again_once_the_trip_closes(): void
    {
        [$driver, $first] = $this->completedTrip();

        $bus = Bus::factory()->withCapacity(40)->create();
        $items = array_map(fn (InspectionItem $i) => ['item' => $i->value, 'passed' => true], InspectionItem::cases());
        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 20000,
        ], $this->authHeader($driver))->assertStatus(201);

        $second = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driver->driver->id,
            'route_id' => Route::factory()->withStops()->create()->id,
        ]);

        $this->postJson("/api/v1/trips/{$second->id}/start", [], $this->authHeader($driver))
            ->assertOk();
    }

    // ====================================================================
    // BR-258 — CORRECTIONS PRESERVE THE ORIGINAL
    // ====================================================================

    #[Test]
    public function a_correction_keeps_the_value_it_replaced(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip(aboard: 5);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count',
            'value' => 7,
            'reason' => 'Two students boarded at the depot before the counter was started.',
        ], $this->authHeader($admin))->assertStatus(201);

        $correction = TripCorrection::first();

        // "What did it say before somebody changed it" must always have an
        // answer.
        $this->assertSame('5', $correction->original_value);
        $this->assertSame('7', $correction->corrected_value);
        $this->assertSame(7, $trip->fresh()->occupied_seat_count);
    }

    #[Test]
    public function a_correction_records_who_made_it_and_why(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip(aboard: 3);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count',
            'value' => 4,
            'reason' => 'Driver reported a miscount at the second stop.',
        ], $this->authHeader($admin))->assertStatus(201);

        $correction = TripCorrection::first();

        $this->assertSame((string) $admin->id, (string) $correction->corrected_by_id);
        $this->assertStringContainsString('miscount', $correction->reason);
    }

    #[Test]
    public function a_correction_requires_a_reason(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip(aboard: 3);

        // A correction with no stated reason is indistinguishable from
        // tampering when somebody reads it back in six months.
        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count',
            'value' => 4,
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    #[Test]
    public function the_status_of_a_trip_cannot_be_corrected(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip();

        // Status, attribution and timestamps are exactly the fields somebody
        // would want to change to hide something.
        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'status',
            'value' => 'CANCELLED',
            'reason' => 'Trying to reclassify a completed trip.',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['field']]);

        $this->assertSame(TripStatus::COMPLETED, $trip->fresh()->status);
    }

    #[Test]
    public function the_driver_of_a_trip_cannot_be_corrected_away(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip();

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'driver_id',
            'value' => Driver::factory()->create()->id,
            'reason' => 'Attempting to reattribute the trip.',
        ], $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function a_running_trip_is_changed_directly_not_corrected(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->runningTrip(aboard: 2);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count',
            'value' => 3,
            'reason' => 'Trying to correct a trip that has not finished.',
        ], $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function a_driver_cannot_correct_a_trip(): void
    {
        [$driver, $trip] = $this->completedTrip(aboard: 3);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count',
            'value' => 0,
            'reason' => 'Rewriting my own attendance record.',
        ], $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function corrections_are_listed_against_the_trip(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip(aboard: 5);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count', 'value' => 6,
            'reason' => 'One late boarder was not counted.',
        ], $this->authHeader($admin))->assertStatus(201);

        $this->getJson("/api/v1/trips/{$trip->id}/corrections", $this->authHeader($admin))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_correction_is_written_to_the_audit_log(): void
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip(aboard: 5);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count', 'value' => 6,
            'reason' => 'Recount after the run.',
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRIP_CORRECTED',
            'record_id' => $trip->id,
        ]);
    }

    // ====================================================================
    // BR-266 — DISAGREEMENTS ARE NOT RECONCILED AWAY
    // ====================================================================

    #[Test]
    public function a_headcount_matching_the_log_raises_nothing(): void
    {
        $student = $this->createStudent();
        [$driver, $trip] = $this->runningTrip();

        $student->student->forceFill(['route_id' => $trip->route_id])->save();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [
            'student_id' => $student->student->id,
        ], $this->authHeader($driver))->assertOk();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [
            'odometer_reading' => 10050,
        ], $this->authHeader($driver))->assertOk();

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));

        $this->assertDatabaseCount('attendance_discrepancies', 0);
    }

    /**
     * A closed trip whose headcount no longer matches its boarding log.
     *
     * The counter and the log move together through the API, so a genuine
     * disagreement comes from the correction path (BR-258): somebody states
     * the count was wrong without being able to produce the missing boarding
     * events. That is exactly the case BR-266 exists for.
     *
     * @return array{0: User, 1: Trip}
     */
    private function tripWithDisagreement(int $counted = 4, int $corrected = 6): array
    {
        $admin = $this->createAdmin();
        [, $trip] = $this->completedTrip(aboard: $counted);

        $this->postJson("/api/v1/trips/{$trip->id}/corrections", [
            'field' => 'occupied_seat_count',
            'value' => $corrected,
            'reason' => 'Driver reported two boarders the counter missed at the depot.',
        ], $this->authHeader($admin))->assertStatus(201);

        return [$admin, $trip->fresh()];
    }

    #[Test]
    public function a_headcount_exceeding_the_log_is_recorded(): void
    {
        [, $trip] = $this->tripWithDisagreement(counted: 4, corrected: 6);

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));

        $discrepancy = AttendanceDiscrepancy::first();

        $this->assertNotNull($discrepancy);
        $this->assertSame(6, $discrepancy->headcount);
        $this->assertSame(4, $discrepancy->boarding_event_count);
        // Positive: somebody is on the bus who is not on any list.
        $this->assertSame(2, $discrepancy->difference);
        $this->assertTrue($discrepancy->isUnderAccounted());
    }

    #[Test]
    public function a_log_exceeding_the_headcount_is_recorded_too(): void
    {
        [, $trip] = $this->tripWithDisagreement(counted: 5, corrected: 3);

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));

        $discrepancy = AttendanceDiscrepancy::first();

        // The other direction matters as well: two people were logged aboard
        // and are not in the final count.
        $this->assertNotNull($discrepancy);
        $this->assertSame(-2, $discrepancy->difference);
        $this->assertFalse($discrepancy->isUnderAccounted());
    }

    #[Test]
    public function both_figures_survive_the_review(): void
    {
        [$admin, $trip] = $this->tripWithDisagreement(counted: 4, corrected: 6);

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));

        $discrepancy = AttendanceDiscrepancy::first();

        $this->postJson("/api/v1/attendance-discrepancies/{$discrepancy->id}/review", [
            'note' => 'Two staff travelled without passes; confirmed with the driver.',
        ], $this->authHeader($admin))->assertOk();

        $reviewed = AttendanceDiscrepancy::find($discrepancy->id);

        // Reviewing explains a disagreement; it does not get to resolve one
        // away by adjusting the number it finds inconvenient.
        $this->assertSame('REVIEWED', $reviewed->status);
        $this->assertSame(6, $reviewed->headcount);
        $this->assertSame(4, $reviewed->boarding_event_count);
    }

    #[Test]
    public function a_review_cannot_alter_either_count(): void
    {
        [$admin, $trip] = $this->tripWithDisagreement();

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));
        $discrepancy = AttendanceDiscrepancy::first();

        // Even if a client submits them, they are not in the FormRequest and
        // the model has no fillable fields.
        $this->postJson("/api/v1/attendance-discrepancies/{$discrepancy->id}/review", [
            'note' => 'Attempting to zero the difference.',
            'headcount' => 0,
            'boarding_event_count' => 0,
            'difference' => 0,
        ], $this->authHeader($admin))->assertOk();

        $reviewed = AttendanceDiscrepancy::find($discrepancy->id);

        $this->assertSame(6, $reviewed->headcount);
        $this->assertSame(2, $reviewed->difference);
    }

    #[Test]
    public function a_review_requires_a_note(): void
    {
        [$admin, $trip] = $this->tripWithDisagreement();

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));
        $discrepancy = AttendanceDiscrepancy::first();

        $this->postJson("/api/v1/attendance-discrepancies/{$discrepancy->id}/review", [],
            $this->authHeader($admin))->assertStatus(422);
    }

    #[Test]
    public function a_discrepancy_cannot_be_reviewed_twice(): void
    {
        [$admin, $trip] = $this->tripWithDisagreement();

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));
        $discrepancy = AttendanceDiscrepancy::first();

        $payload = ['note' => 'Confirmed with the driver on the day.'];

        $this->postJson("/api/v1/attendance-discrepancies/{$discrepancy->id}/review", $payload,
            $this->authHeader($admin))->assertOk();
        $this->postJson("/api/v1/attendance-discrepancies/{$discrepancy->id}/review", $payload,
            $this->authHeader($admin))->assertStatus(409);
    }

    #[Test]
    public function reconciliation_does_not_duplicate_on_a_second_run(): void
    {
        [, $trip] = $this->tripWithDisagreement();

        $job = new ReconcileAttendance;
        $job->handle(app(TripRecoveryService::class));
        $job->handle(app(TripRecoveryService::class));

        $this->assertDatabaseCount('attendance_discrepancies', 1);
    }

    #[Test]
    public function a_driver_cannot_see_the_discrepancy_queue(): void
    {
        [$driver] = $this->runningTrip();

        $this->getJson('/api/v1/attendance-discrepancies', $this->authHeader($driver))
            ->assertStatus(403);
    }

    #[Test]
    public function a_discrepancy_is_written_to_the_audit_log(): void
    {
        [, $trip] = $this->tripWithDisagreement();

        (new ReconcileAttendance)->handle(app(TripRecoveryService::class));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ATTENDANCE_DISCREPANCY_RAISED',
        ]);
    }
}
