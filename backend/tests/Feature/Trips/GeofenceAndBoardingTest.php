<?php

namespace Tests\Feature\Trips;

use App\Enums\InspectionItem;
use App\Enums\StopProgressState;
use App\Models\Bus;
use App\Models\Notification;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Student;
use App\Models\Trip;
use App\Models\TripStopProgress;
use App\Models\User;
use App\Services\Tracking\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-07, FR-08 — geofence state machine and passenger counting.
 *
 * The geofence tests are the ones that matter most: a single-point arrival
 * check fires "your bus is here" on the first GPS drift, and sends a child
 * into the road for a bus that is two streets away.
 */
class GeofenceAndBoardingTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 12.9716;

    private const LNG = 77.5946;

    /**
     * A running trip whose first stop is exactly at the reference position.
     *
     * @return array{0: User, 1: Trip, 2: RouteStop}
     */
    private function runningTripAtStop(): array
    {
        $driverUser = $this->createDriver();
        $bus = Bus::factory()->withCapacity(3)->create();

        $items = array_map(fn (InspectionItem $item) => [
            'item' => $item->value, 'passed' => true,
        ], InspectionItem::cases());

        $this->postJson("/api/v1/buses/{$bus->id}/inspections", [
            'items' => $items, 'odometer_reading' => 10000,
        ], $this->authHeader($driverUser))->assertStatus(201);

        $route = Route::factory()->create();
        $stop = RouteStop::factory()->for($route)->atSequence(1)
            ->at(self::LAT, self::LNG)->create(['stop_name' => 'Gandhi Nagar']);
        RouteStop::factory()->for($route)->atSequence(2)
            ->at(self::LAT + 0.2, self::LNG)->create(['stop_name' => 'Far Stop']);
        $route->syncStopCount();

        $trip = Trip::factory()->departingNow()->create([
            'bus_id' => $bus->id,
            'driver_id' => $driverUser->driver->id,
            'route_id' => $route->id,
        ]);

        $this->postJson("/api/v1/trips/{$trip->id}/start", [], $this->authHeader($driverUser))
            ->assertOk();

        app(GeofenceService::class)->initialiseFor($trip->fresh());

        return [$driverUser, $trip->fresh(), $stop];
    }

    private function reportPosition(User $driver, Trip $trip, float $lat, float $lng): void
    {
        $this->postJson("/api/v1/trips/{$trip->id}/positions", [
            'latitude' => $lat,
            'longitude' => $lng,
        ], $this->authHeader($driver))->assertOk();
    }

    private function progressFor(Trip $trip, RouteStop $stop): TripStopProgress
    {
        return TripStopProgress::where('trip_id', $trip->id)
            ->where('route_stop_id', $stop->id)
            ->firstOrFail();
    }

    // ====================================================================
    // GEOFENCE STATE MACHINE — BR-308
    // ====================================================================

    #[Test]
    public function a_stop_starts_pending(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->assertSame(StopProgressState::PENDING, $this->progressFor($trip, $stop)->state);
    }

    #[Test]
    public function entering_the_geofence_moves_the_stop_to_approaching(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);

        $this->assertSame(StopProgressState::APPROACHING, $this->progressFor($trip, $stop)->state);
    }

    #[Test]
    public function a_single_reading_does_not_confirm_arrival(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);

        // One point inside the fence is not an arrival — it may be drift, or a
        // bus on a parallel road.
        $this->assertNotSame(StopProgressState::ARRIVED, $this->progressFor($trip, $stop)->state);
    }

    #[Test]
    public function consecutive_readings_confirm_arrival(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);
        $this->travel(10)->seconds();
        $this->reportPosition($driver, $trip, self::LAT + 0.00001, self::LNG);

        $progress = $this->progressFor($trip, $stop);

        $this->assertSame(StopProgressState::ARRIVED, $progress->state);
        $this->assertNotNull($progress->arrived_at);
    }

    #[Test]
    public function drifting_out_before_confirmation_resets_to_pending(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);
        $this->assertSame(StopProgressState::APPROACHING, $this->progressFor($trip, $stop)->state);

        // Moves away again without ever confirming. Five minutes for ~2.2km
        // is about 26 km/h — plausible, so the pipeline accepts it.
        $this->travel(5)->minutes();
        $this->reportPosition($driver, $trip, self::LAT + 0.02, self::LNG);

        $progress = $this->progressFor($trip, $stop);

        $this->assertSame(StopProgressState::PENDING, $progress->state);
        $this->assertSame(0, $progress->inside_readings);
        $this->assertNull($progress->arrived_at);
    }

    #[Test]
    public function leaving_after_arrival_marks_departure(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);
        $this->travel(10)->seconds();
        $this->reportPosition($driver, $trip, self::LAT + 0.00001, self::LNG);
        $this->travel(3)->minutes();
        $this->reportPosition($driver, $trip, self::LAT + 0.01, self::LNG);

        $progress = $this->progressFor($trip, $stop);

        $this->assertSame(StopProgressState::DEPARTED, $progress->state);
        $this->assertNotNull($progress->departed_at);
    }

    #[Test]
    public function a_distant_stop_stays_pending(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);

        $farStop = $trip->route->stops()->where('sequence_number', 2)->first();

        $this->assertSame(StopProgressState::PENDING, $this->progressFor($trip, $farStop)->state);
    }

    #[Test]
    public function approaching_notifies_the_students_waiting_there(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);

        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'trip.stop.approaching')->count());
    }

    #[Test]
    public function approaching_notifies_once_per_stop_per_trip(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        // Enter, drift out, enter again.
        $this->reportPosition($driver, $trip, self::LAT, self::LNG);
        $this->travel(5)->minutes();
        $this->reportPosition($driver, $trip, self::LAT + 0.02, self::LNG);
        $this->travel(5)->minutes();
        $this->reportPosition($driver, $trip, self::LAT, self::LNG);

        // Repeated alerts train people to ignore them.
        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'trip.stop.approaching')->count());
    }

    #[Test]
    public function students_at_other_stops_are_not_notified(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();
        $farStop = $trip->route->stops()->where('sequence_number', 2)->first();

        $elsewhere = $this->createStudent();
        $elsewhere->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $farStop->id,
        ])->save();

        $this->reportPosition($driver, $trip, self::LAT, self::LNG);

        $this->assertSame(0, Notification::where('user_id', $elsewhere->id)
            ->where('event_key', 'trip.stop.approaching')->count());
    }

    // ====================================================================
    // MANUAL ARRIVAL AND SKIPPING — BR-306
    // ====================================================================

    #[Test]
    public function a_driver_can_mark_arrival_manually_when_gps_fails(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/arrive", [],
            $this->authHeader($driver))->assertOk();

        $this->assertSame(StopProgressState::ARRIVED, $this->progressFor($trip, $stop)->state);
    }

    #[Test]
    public function marking_arrival_twice_is_idempotent(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/arrive", [],
            $this->authHeader($driver))->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/arrive", [],
            $this->authHeader($driver))->assertOk();

        $this->assertSame(StopProgressState::ARRIVED, $this->progressFor($trip, $stop)->state);
    }

    #[Test]
    public function a_driver_can_skip_a_stop_with_a_reason(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/skip",
            ['reason' => 'Road closed by police for an incident.'],
            $this->authHeader($driver))->assertOk();

        $this->assertSame(StopProgressState::SKIPPED, $this->progressFor($trip, $stop)->state);
    }

    #[Test]
    public function skipping_a_stop_immediately_notifies_the_students_waiting(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/skip",
            ['reason' => 'Road closed by police for an incident.'],
            $this->authHeader($driver))->assertOk();

        // Telling them when the trip ends is telling them far too late.
        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'trip.stop.skipped')->count());
    }

    #[Test]
    public function skipping_requires_a_reason(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/skip", [],
            $this->authHeader($driver))->assertStatus(422);
    }

    #[Test]
    public function a_stop_from_another_trip_cannot_be_reached(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();
        $otherRoute = Route::factory()->withStops()->create();
        $otherStop = $otherRoute->stops()->first();

        $this->postJson("/api/v1/trips/{$trip->id}/stops/{$otherStop->id}/arrive", [],
            $this->authHeader($driver))->assertStatus(404);
    }

    // ====================================================================
    // PASSENGER COUNTING — BR-254, BR-255, BR-256
    // ====================================================================

    #[Test]
    public function a_driver_can_count_a_passenger_aboard(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.occupied', 1);
    }

    #[Test]
    public function boarding_is_refused_at_capacity(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        // The bus seats three.
        foreach (range(1, 3) as $ignored) {
            $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))
                ->assertOk();
        }

        $response = $this->postJson("/api/v1/trips/{$trip->id}/board", [],
            $this->authHeader($driver))->assertStatus(409);

        // Overloading is illegal and it is what kills people in a crash.
        $this->assertSame(3, $response->json('errors.occupied'));
        $this->assertSame(3, $trip->fresh()->occupied_seat_count);
    }

    #[Test]
    public function the_count_cannot_go_below_zero(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/alight", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function alighting_reduces_the_count(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/alight", [], $this->authHeader($driver))
            ->assertOk()
            ->assertJsonPath('data.occupied', 0);
    }

    #[Test]
    public function a_named_boarding_notifies_the_student(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [
            'student_id' => $student->student->id,
            'route_stop_id' => $stop->id,
        ], $this->authHeader($driver))->assertOk();

        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'trip.passenger.boarded')->count());
    }

    #[Test]
    public function a_student_cannot_board_the_same_trip_twice(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();
        $student = $this->createStudent();

        $payload = ['student_id' => $student->student->id];

        $this->postJson("/api/v1/trips/{$trip->id}/board", $payload, $this->authHeader($driver))
            ->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/board", $payload, $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function a_replayed_boarding_is_absorbed(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $payload = ['idempotency_key' => 'offline-board-1'];

        $this->postJson("/api/v1/trips/{$trip->id}/board", $payload, $this->authHeader($driver))
            ->assertOk();
        $this->postJson("/api/v1/trips/{$trip->id}/board", $payload, $this->authHeader($driver))
            ->assertOk();

        // A retried sync must not count the same student twice.
        $this->assertSame(1, $trip->fresh()->occupied_seat_count);
    }

    #[Test]
    public function counting_is_refused_once_the_trip_closes(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/complete", [], $this->authHeader($driver))
            ->assertOk();

        // BR-257 — attendance freezes when a trip closes.
        $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($driver))
            ->assertStatus(409);
    }

    #[Test]
    public function another_driver_cannot_count_passengers(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();
        $intruder = $this->createDriver();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($intruder))
            ->assertStatus(403);
    }

    #[Test]
    public function a_student_cannot_count_themselves_aboard(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();
        $student = $this->createStudent();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [], $this->authHeader($student))
            ->assertStatus(403);
    }

    // ====================================================================
    // LEFT BEHIND — BR-255
    // ====================================================================

    #[Test]
    public function students_left_behind_are_recorded_and_notified(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $this->postJson("/api/v1/trips/{$trip->id}/left-behind", [
            'student_ids' => [$student->student->id],
            'route_stop_id' => $stop->id,
        ], $this->authHeader($driver))->assertOk();

        // Silence here is what destroys trust in the whole service.
        $this->assertSame(1, Notification::where('user_id', $student->id)
            ->where('event_key', 'trip.passengers.left_behind')->count());
    }

    #[Test]
    public function operations_are_alerted_when_students_are_left_behind(): void
    {
        $admin = $this->createAdmin();
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();

        $this->postJson("/api/v1/trips/{$trip->id}/left-behind", [
            'student_ids' => [$student->student->id],
        ], $this->authHeader($driver))->assertOk();

        $this->assertSame(1, Notification::where('user_id', $admin->id)
            ->where('event_key', 'trip.passengers.left_behind.operations')->count());
    }

    #[Test]
    public function left_behind_requires_at_least_one_student(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $this->postJson("/api/v1/trips/{$trip->id}/left-behind", ['student_ids' => []],
            $this->authHeader($driver))->assertStatus(422);
    }

    // ====================================================================
    // MANIFEST
    // ====================================================================

    #[Test]
    public function the_manifest_lists_who_is_expected_at_a_stop(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $response = $this->getJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/manifest",
            $this->authHeader($driver))->assertOk();

        $this->assertSame(1, $response->json('data.expected_count'));
        $this->assertFalse($response->json('data.expected.0.boarded'));
    }

    #[Test]
    public function the_manifest_reflects_who_has_boarded(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $this->postJson("/api/v1/trips/{$trip->id}/board", [
            'student_id' => $student->student->id,
            'route_stop_id' => $stop->id,
        ], $this->authHeader($driver))->assertOk();

        $response = $this->getJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/manifest",
            $this->authHeader($driver))->assertOk();

        $this->assertTrue($response->json('data.expected.0.boarded'));
    }

    #[Test]
    public function the_manifest_does_not_expose_student_addresses(): void
    {
        [$driver, $trip, $stop] = $this->runningTripAtStop();

        $student = $this->createStudent(profileAttributes: ['hostel_name' => 'Block C Secret']);
        $student->student->forceFill([
            'route_id' => $trip->route_id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $response = $this->getJson("/api/v1/trips/{$trip->id}/stops/{$stop->id}/manifest",
            $this->authHeader($driver))->assertOk();

        // A driver needs to know who to expect, not where they live.
        $this->assertStringNotContainsString('Block C Secret', $response->getContent());
    }
}
