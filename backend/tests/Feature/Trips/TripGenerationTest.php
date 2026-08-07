<?php

namespace Tests\Feature\Trips;

use App\Enums\BusStatus;
use App\Enums\DayOfWeek;
use App\Enums\DocumentType;
use App\Enums\ScheduleFrequency;
use App\Enums\ServiceDayType;
use App\Enums\TripStatus;
use App\Jobs\GenerateDailyTrips;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\ServiceCalendarDay;
use App\Models\Trip;
use App\Services\Trips\TripGenerationService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BG-01, BG-02 — turning the timetable into a day's trips.
 *
 * Covers BR-263 (idempotent), BR-264 (skip non-operating days), BR-265
 * (override with a reason).
 */
class TripGenerationTest extends TestCase
{
    use RefreshDatabase;

    private TripGenerationService $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = app(TripGenerationService::class);
    }

    private function nextMonday(): CarbonInterface
    {
        return Carbon::today()->next(CarbonInterface::MONDAY);
    }

    private function mondaySchedule(array $overrides = []): Schedule
    {
        return Schedule::factory()->create(array_merge([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::WEEKDAYS->value,
        ], $overrides));
    }

    // ====================================================================
    // GENERATION
    // ====================================================================

    #[Test]
    public function it_creates_a_trip_for_each_matching_schedule(): void
    {
        $this->mondaySchedule();
        $this->mondaySchedule(['departure_time' => '16:00:00', 'arrival_time' => '17:00:00']);

        $result = $this->generator->generateFor($this->nextMonday());

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseCount('trips', 2);
    }

    #[Test]
    public function a_generated_trip_starts_scheduled(): void
    {
        $schedule = $this->mondaySchedule();

        $this->generator->generateFor($this->nextMonday());

        $trip = Trip::first();

        $this->assertSame(TripStatus::SCHEDULED, $trip->status);
        $this->assertNotNull($trip->generated_at);
        $this->assertSame($schedule->id, $trip->schedule_id);
    }

    #[Test]
    public function a_generated_trip_inherits_the_schedule_times_and_resources(): void
    {
        $schedule = $this->mondaySchedule();

        $this->generator->generateFor($this->nextMonday());

        $trip = Trip::first();

        $this->assertSame($schedule->bus_id, $trip->bus_id);
        $this->assertSame($schedule->driver_id, $trip->driver_id);
        $this->assertSame($schedule->route_id, $trip->route_id);
        $this->assertSame('08:00:00', $trip->scheduled_departure_time);
    }

    #[Test]
    public function it_ignores_schedules_for_other_days(): void
    {
        $this->mondaySchedule(['day_of_week' => DayOfWeek::FRIDAY->value]);

        $result = $this->generator->generateFor($this->nextMonday());

        $this->assertSame(0, $result['created']);
    }

    #[Test]
    public function it_ignores_inactive_schedules(): void
    {
        Schedule::factory()->inactive()->create([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::WEEKDAYS->value,
        ]);

        $this->assertSame(0, $this->generator->generateFor($this->nextMonday())['created']);
    }

    #[Test]
    public function the_booked_seat_count_comes_from_the_routes_riders(): void
    {
        $schedule = $this->mondaySchedule();

        foreach (range(1, 3) as $ignored) {
            $this->createStudent()->student
                ->forceFill(['route_id' => $schedule->route_id])->save();
        }

        $this->generator->generateFor($this->nextMonday());

        $this->assertSame(3, Trip::first()->booked_seat_count);
    }

    // ====================================================================
    // IDEMPOTENCE — BR-263
    // ====================================================================

    #[Test]
    public function running_generation_twice_creates_nothing_the_second_time(): void
    {
        $this->mondaySchedule();

        $first = $this->generator->generateFor($this->nextMonday());
        $second = $this->generator->generateFor($this->nextMonday());

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertDatabaseCount('trips', 1);
    }

    #[Test]
    public function re_running_after_a_fix_generates_only_the_missing_trips(): void
    {
        $this->mondaySchedule();

        $this->generator->generateFor($this->nextMonday());

        // A second schedule is added after the first run — the documented
        // recovery is simply to re-run.
        $this->mondaySchedule(['departure_time' => '16:00:00', 'arrival_time' => '17:00:00']);

        $result = $this->generator->generateFor($this->nextMonday());

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseCount('trips', 2);
    }

    #[Test]
    public function generation_for_different_dates_is_independent(): void
    {
        $this->mondaySchedule(['frequency' => ScheduleFrequency::DAILY->value]);

        $this->generator->generateFor($this->nextMonday());
        $this->generator->generateFor($this->nextMonday()->addWeek());

        $this->assertDatabaseCount('trips', 2);
    }

    // ====================================================================
    // SERVICE CALENDAR — BR-264
    // ====================================================================

    #[Test]
    public function nothing_is_generated_on_a_holiday(): void
    {
        $this->mondaySchedule();
        $monday = $this->nextMonday();

        ServiceCalendarDay::create([
            'date' => $monday->toDateString(),
            'day_type' => ServiceDayType::HOLIDAY->value,
            'reason' => 'Republic Day',
        ]);

        $result = $this->generator->generateFor($monday);

        $this->assertTrue($result['suspended']);
        $this->assertSame('Republic Day', $result['reason']);
        $this->assertDatabaseCount('trips', 0);
    }

    #[Test]
    public function nothing_is_generated_during_a_suspension(): void
    {
        $this->mondaySchedule();
        $monday = $this->nextMonday();

        ServiceCalendarDay::create([
            'date' => $monday->toDateString(),
            'day_type' => ServiceDayType::SUSPENSION->value,
            'reason' => 'Severe flooding across the district',
        ]);

        $this->assertTrue($this->generator->generateFor($monday)['suspended']);
    }

    #[Test]
    public function a_special_timetable_day_still_generates(): void
    {
        $this->mondaySchedule();
        $monday = $this->nextMonday();

        ServiceCalendarDay::create([
            'date' => $monday->toDateString(),
            'day_type' => ServiceDayType::SPECIAL->value,
            'reason' => 'Exam week timings',
        ]);

        // The service runs; only the timings differ.
        $this->assertFalse($this->generator->generateFor($monday)['suspended']);
        $this->assertDatabaseCount('trips', 1);
    }

    #[Test]
    public function a_suspension_is_audited_so_the_absence_is_explicable(): void
    {
        $this->mondaySchedule();
        $monday = $this->nextMonday();

        ServiceCalendarDay::create([
            'date' => $monday->toDateString(),
            'day_type' => ServiceDayType::HOLIDAY->value,
            'reason' => 'Founders Day',
        ]);

        $this->generator->generateFor($monday);

        $this->assertDatabaseHas('audit_logs', ['action' => 'TRIP_GENERATION_SKIPPED']);
    }

    // ====================================================================
    // EXCEPTIONS — BG-02
    // ====================================================================

    #[Test]
    public function an_unavailable_bus_is_reported_as_a_blocking_exception(): void
    {
        $schedule = $this->mondaySchedule();
        $schedule->bus->forceFill(['status' => BusStatus::MAINTENANCE])->save();

        $result = $this->generator->generateFor($this->nextMonday());

        // The trip is still created; the gap is surfaced for the morning
        // review rather than silently resolved.
        $this->assertSame(1, $result['created']);
        $this->assertNotEmpty($result['exceptions']);
        $this->assertSame('BUS_UNAVAILABLE', $result['exceptions'][0]['type']);
        $this->assertTrue($result['exceptions'][0]['blocking']);
    }

    #[Test]
    public function an_expired_document_is_reported_as_a_blocking_exception(): void
    {
        $bus = Bus::factory()->withExpiredDocument(DocumentType::INSURANCE)->create();
        $this->mondaySchedule(['bus_id' => $bus->id]);

        $result = $this->generator->generateFor($this->nextMonday());

        $types = array_column($result['exceptions'], 'type');

        $this->assertContains('DOCUMENT_EXPIRED', $types);
    }

    #[Test]
    public function an_expired_licence_is_reported_as_a_blocking_exception(): void
    {
        $driver = Driver::factory()->licenceExpired()->create();
        $this->mondaySchedule(['driver_id' => $driver->id]);

        $result = $this->generator->generateFor($this->nextMonday());

        $this->assertContains('LICENCE_EXPIRED', array_column($result['exceptions'], 'type'));
    }

    #[Test]
    public function a_route_with_no_riders_is_a_non_blocking_exception(): void
    {
        $this->mondaySchedule();

        $result = $this->generator->generateFor($this->nextMonday());

        $noPassengers = collect($result['exceptions'])->firstWhere('type', 'NO_PASSENGERS');

        $this->assertNotNull($noPassengers);
        $this->assertFalse($noPassengers['blocking']);
    }

    #[Test]
    public function a_healthy_schedule_produces_no_blocking_exceptions(): void
    {
        $schedule = $this->mondaySchedule();
        $this->createStudent()->student->forceFill(['route_id' => $schedule->route_id])->save();

        $result = $this->generator->generateFor($this->nextMonday());

        $blocking = array_filter($result['exceptions'], fn ($e) => $e['blocking']);

        $this->assertSame([], $blocking);
    }

    // ====================================================================
    // THE JOB
    // ====================================================================

    #[Test]
    public function the_nightly_job_generates_tomorrows_trips(): void
    {
        $tomorrow = Carbon::tomorrow();

        Schedule::factory()->create([
            'day_of_week' => DayOfWeek::fromDate($tomorrow)->value,
            'frequency' => ScheduleFrequency::DAILY->value,
        ]);

        (new GenerateDailyTrips)->handle($this->generator);

        // Date columns carry a midnight time component in storage, so the
        // comparison is made by date rather than by raw value.
        $this->assertTrue(Trip::whereDate('trip_date', $tomorrow->toDateString())->exists());
    }

    #[Test]
    public function the_job_can_be_run_for_a_specific_date(): void
    {
        $monday = $this->nextMonday();
        $this->mondaySchedule();

        (new GenerateDailyTrips($monday->toDateString()))->handle($this->generator);

        $this->assertTrue(Trip::whereDate('trip_date', $monday->toDateString())->exists());
    }

    // ====================================================================
    // THE ENDPOINT — AD-66
    // ====================================================================

    #[Test]
    public function an_admin_can_run_generation_on_demand(): void
    {
        $admin = $this->createAdmin();
        $this->mondaySchedule();

        $this->postJson('/api/v1/trips/generate', ['date' => $this->nextMonday()->toDateString()],
            $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.created', 1);
    }

    #[Test]
    public function generation_reports_the_exception_list(): void
    {
        $admin = $this->createAdmin();
        $schedule = $this->mondaySchedule();
        $schedule->bus->forceFill(['status' => BusStatus::BREAKDOWN])->save();

        $response = $this->postJson('/api/v1/trips/generate',
            ['date' => $this->nextMonday()->toDateString()], $this->authHeader($admin))->assertOk();

        $this->assertNotEmpty($response->json('data.exceptions'));
    }

    #[Test]
    public function a_driver_cannot_run_generation(): void
    {
        $driver = $this->createDriver();

        $this->postJson('/api/v1/trips/generate', ['date' => $this->nextMonday()->toDateString()],
            $this->authHeader($driver))->assertStatus(403);
    }

    #[Test]
    public function generation_requires_a_date(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/trips/generate', [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date']]);
    }

    // ====================================================================
    // AD-HOC TRIPS — BR-265
    // ====================================================================

    #[Test]
    public function an_admin_can_create_an_ad_hoc_trip(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        $this->postJson('/api/v1/trips', [
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->create()->id,
            'driver_id' => Driver::factory()->create()->id,
            'trip_date' => now()->addDay()->toDateString(),
            'scheduled_departure_time' => '14:00:00',
            'scheduled_arrival_time' => '15:00:00',
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseCount('trips', 1);
    }

    #[Test]
    public function an_ad_hoc_trip_on_a_holiday_needs_an_override_reason(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $date = now()->addDay();

        ServiceCalendarDay::create([
            'date' => $date->toDateString(),
            'day_type' => ServiceDayType::HOLIDAY->value,
            'reason' => 'Public holiday',
        ]);

        $payload = [
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->create()->id,
            'driver_id' => Driver::factory()->create()->id,
            'trip_date' => $date->toDateString(),
            'scheduled_departure_time' => '14:00:00',
            'scheduled_arrival_time' => '15:00:00',
        ];

        $this->postJson('/api/v1/trips', $payload, $this->authHeader($admin))->assertStatus(409);

        // BR-265 — sometimes right, never accidental.
        $payload['override_reason'] = 'Examination transport agreed with the principal.';

        $this->postJson('/api/v1/trips', $payload, $this->authHeader($admin))->assertStatus(201);
    }

    #[Test]
    public function an_ad_hoc_trip_cannot_double_book_a_bus(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();
        $bus = Bus::factory()->create();

        $existing = Trip::factory()->create([
            'bus_id' => $bus->id,
            'trip_date' => now()->addDay()->toDateString(),
            'scheduled_departure_time' => '14:00:00',
            'scheduled_arrival_time' => '15:00:00',
        ]);

        $this->postJson('/api/v1/trips', [
            'route_id' => $route->id,
            'bus_id' => $bus->id,
            'driver_id' => Driver::factory()->create()->id,
            'trip_date' => now()->addDay()->toDateString(),
            'scheduled_departure_time' => '14:30:00',
            'scheduled_arrival_time' => '15:30:00',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['bus_id']]);
    }

    #[Test]
    public function an_ad_hoc_trip_cannot_be_created_in_the_past(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->withStops()->create();

        $this->postJson('/api/v1/trips', [
            'route_id' => $route->id,
            'bus_id' => Bus::factory()->create()->id,
            'driver_id' => Driver::factory()->create()->id,
            'trip_date' => now()->subWeek()->toDateString(),
            'scheduled_departure_time' => '14:00:00',
            'scheduled_arrival_time' => '15:00:00',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['trip_date']]);
    }

    // ====================================================================
    // SERVICE CALENDAR API
    // ====================================================================

    #[Test]
    public function an_admin_can_declare_a_non_operating_day(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/service-calendar', [
            'date' => now()->addWeek()->toDateString(),
            'day_type' => 'HOLIDAY',
            'reason' => 'Independence Day',
        ], $this->authHeader($admin))->assertStatus(201);

        $this->assertDatabaseCount('service_calendar_days', 1);
    }

    #[Test]
    public function declaring_a_suspension_reports_trips_already_scheduled(): void
    {
        $admin = $this->createAdmin();
        $date = now()->addWeek();

        Trip::factory()->on($date->toDateString())->create();

        $response = $this->postJson('/api/v1/service-calendar', [
            'date' => $date->toDateString(),
            'day_type' => 'SUSPENSION',
            'reason' => 'Cyclone warning',
        ], $this->authHeader($admin))->assertStatus(201);

        // The operator is told what still needs cancelling.
        $this->assertSame(1, $response->json('data.trips_already_scheduled'));
    }

    #[Test]
    public function a_date_cannot_be_declared_twice(): void
    {
        $admin = $this->createAdmin();
        $date = now()->addWeek()->toDateString();

        ServiceCalendarDay::create([
            'date' => $date,
            'day_type' => ServiceDayType::HOLIDAY->value,
            'reason' => 'First declaration',
        ]);

        $this->postJson('/api/v1/service-calendar', [
            'date' => $date,
            'day_type' => 'HOLIDAY',
            'reason' => 'Second declaration',
        ], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date']]);
    }

    #[Test]
    public function a_student_cannot_declare_a_non_operating_day(): void
    {
        $student = $this->createStudent();

        $this->postJson('/api/v1/service-calendar', [
            'date' => now()->addWeek()->toDateString(),
            'day_type' => 'HOLIDAY',
            'reason' => 'I would like a day off',
        ], $this->authHeader($student))->assertStatus(403);
    }

    #[Test]
    public function any_authenticated_user_can_read_the_calendar(): void
    {
        ServiceCalendarDay::create([
            'date' => now()->addWeek()->toDateString(),
            'day_type' => ServiceDayType::HOLIDAY->value,
            'reason' => 'Public holiday',
        ]);

        foreach ([$this->createAdmin(), $this->createDriver(), $this->createStudent()] as $user) {
            $this->getJson('/api/v1/service-calendar', $this->authHeader($user))->assertOk();
        }
    }

    #[Test]
    public function removing_a_calendar_entry_restores_normal_service(): void
    {
        $admin = $this->createAdmin();
        $monday = $this->nextMonday();
        $this->mondaySchedule();

        $day = ServiceCalendarDay::create([
            'date' => $monday->toDateString(),
            'day_type' => ServiceDayType::HOLIDAY->value,
            'reason' => 'Declared in error',
        ]);

        $this->deleteJson("/api/v1/service-calendar/{$day->id}", [], $this->authHeader($admin))
            ->assertOk();

        $this->assertFalse($this->generator->generateFor($monday)['suspended']);
    }
}
