<?php

namespace Tests\Unit\Models;

use App\Enums\DayOfWeek;
use App\Enums\ScheduleFrequency;
use App\Models\Schedule;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schedule recurrence and overlap logic.
 *
 * These are the calculations trip generation (BG-01) will depend on, so they
 * are tested directly rather than only through the API.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(array $attributes = []): Schedule
    {
        return Schedule::factory()->make($attributes);
    }

    // ====================================================================
    // runsOn() — BR-263, BR-264 depend on this
    // ====================================================================

    #[Test]
    public function it_runs_on_a_matching_weekday(): void
    {
        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::WEEKDAYS->value,
        ]);

        $this->assertTrue($schedule->runsOn(now()->next(CarbonInterface::MONDAY)));
    }

    #[Test]
    public function it_does_not_run_on_a_different_weekday(): void
    {
        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::WEEKDAYS->value,
        ]);

        $this->assertFalse($schedule->runsOn(now()->next(CarbonInterface::WEDNESDAY)));
    }

    #[Test]
    public function an_inactive_schedule_never_runs(): void
    {
        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'is_active' => false,
        ]);

        $this->assertFalse($schedule->runsOn(now()->next(CarbonInterface::MONDAY)));
    }

    #[Test]
    public function a_weekdays_schedule_does_not_run_at_the_weekend(): void
    {
        // A Saturday schedule with WEEKDAYS frequency is contradictory; the
        // frequency must veto the weekday, not the other way round.
        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::SATURDAY->value,
            'frequency' => ScheduleFrequency::WEEKDAYS->value,
        ]);

        $this->assertFalse($schedule->runsOn(now()->next(CarbonInterface::SATURDAY)));
    }

    #[Test]
    public function a_weekends_schedule_runs_on_saturday(): void
    {
        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::SATURDAY->value,
            'frequency' => ScheduleFrequency::WEEKENDS->value,
        ]);

        $this->assertTrue($schedule->runsOn(now()->next(CarbonInterface::SATURDAY)));
    }

    #[Test]
    public function it_does_not_run_before_its_start_date(): void
    {
        $monday = now()->next(CarbonInterface::MONDAY);

        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::DAILY->value,
            'start_date' => $monday->copy()->addWeek(),
        ]);

        $this->assertFalse($schedule->runsOn($monday));
    }

    #[Test]
    public function it_does_not_run_after_its_end_date(): void
    {
        $monday = now()->next(CarbonInterface::MONDAY);

        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::DAILY->value,
            'end_date' => $monday->copy()->subDay(),
        ]);

        $this->assertFalse($schedule->runsOn($monday));
    }

    #[Test]
    public function it_runs_on_the_first_day_of_its_validity_window(): void
    {
        $monday = now()->next(CarbonInterface::MONDAY);

        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::DAILY->value,
            'start_date' => $monday,
            'end_date' => $monday,
        ]);

        // Boundaries are inclusive: a term that starts today includes today.
        $this->assertTrue($schedule->runsOn($monday));
    }

    #[Test]
    public function it_runs_on_the_last_day_of_its_validity_window(): void
    {
        $monday = now()->next(CarbonInterface::MONDAY);

        $schedule = $this->schedule([
            'day_of_week' => DayOfWeek::MONDAY->value,
            'frequency' => ScheduleFrequency::DAILY->value,
            'end_date' => $monday,
        ]);

        $this->assertTrue($schedule->runsOn($monday->copy()->endOfDay()));
    }

    // ====================================================================
    // overlapsTimeWindowOf() — BR-208
    // ====================================================================

    #[Test]
    public function overlapping_windows_are_detected(): void
    {
        $a = $this->schedule(['departure_time' => '08:00:00', 'arrival_time' => '09:00:00']);
        $b = $this->schedule(['departure_time' => '08:30:00', 'arrival_time' => '09:30:00']);

        $this->assertTrue($a->overlapsTimeWindowOf($b));
        $this->assertTrue($b->overlapsTimeWindowOf($a));
    }

    #[Test]
    public function a_contained_window_overlaps(): void
    {
        $a = $this->schedule(['departure_time' => '08:00:00', 'arrival_time' => '10:00:00']);
        $b = $this->schedule(['departure_time' => '08:30:00', 'arrival_time' => '09:00:00']);

        $this->assertTrue($a->overlapsTimeWindowOf($b));
    }

    #[Test]
    public function touching_windows_do_not_overlap(): void
    {
        // BR-208: a bus arriving at 09:00 is free to depart again at 09:00.
        $a = $this->schedule(['departure_time' => '08:00:00', 'arrival_time' => '09:00:00']);
        $b = $this->schedule(['departure_time' => '09:00:00', 'arrival_time' => '10:00:00']);

        $this->assertFalse($a->overlapsTimeWindowOf($b));
        $this->assertFalse($b->overlapsTimeWindowOf($a));
    }

    #[Test]
    public function separate_windows_do_not_overlap(): void
    {
        $a = $this->schedule(['departure_time' => '08:00:00', 'arrival_time' => '09:00:00']);
        $b = $this->schedule(['departure_time' => '16:00:00', 'arrival_time' => '17:00:00']);

        $this->assertFalse($a->overlapsTimeWindowOf($b));
    }
}
