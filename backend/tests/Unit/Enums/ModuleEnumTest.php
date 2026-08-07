<?php

namespace Tests\Unit\Enums;

use App\Enums\DayOfWeek;
use App\Enums\RouteStatus;
use App\Enums\ScheduleFrequency;
use App\Enums\StopType;
use App\Enums\StudentStatus;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Enum vocabulary and derived behaviour for the network and student module.
 *
 * BR-004 depends on every value being canonical uppercase and identical to its
 * case name — a mismatch between enum, database column and validation rule is
 * the defect class this guards against.
 */
class ModuleEnumTest extends TestCase
{
    // ====================================================================
    // Canonical values — BR-004
    // ====================================================================

    #[Test]
    public function every_enum_value_matches_its_case_name(): void
    {
        $enums = [RouteStatus::class, StudentStatus::class, StopType::class, DayOfWeek::class, ScheduleFrequency::class];

        foreach ($enums as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertSame(
                    $case->name,
                    $case->value,
                    "{$enum}::{$case->name} value must equal its name",
                );
                $this->assertSame(
                    strtoupper($case->value),
                    $case->value,
                    "{$enum}::{$case->name} value must be uppercase",
                );
            }
        }
    }

    #[Test]
    public function values_helper_returns_every_case(): void
    {
        $this->assertSame(['ACTIVE', 'INACTIVE', 'MAINTENANCE'], RouteStatus::values());
        $this->assertSame(['ACTIVE', 'INACTIVE', 'SUSPENDED'], StudentStatus::values());
        $this->assertSame(['PICKUP', 'DROPOFF', 'BOTH'], StopType::values());
        $this->assertCount(7, DayOfWeek::values());
        $this->assertSame(['DAILY', 'WEEKDAYS', 'WEEKENDS', 'ONCE'], ScheduleFrequency::values());
    }

    #[Test]
    public function lowercase_input_does_not_resolve(): void
    {
        // Case-insensitive resolution would reintroduce the bug BR-004 exists
        // to prevent.
        $this->assertNull(RouteStatus::tryFrom('active'));
        $this->assertNull(StudentStatus::tryFrom('active'));
        $this->assertNull(StopType::tryFrom('pickup'));
    }

    // ====================================================================
    // RouteStatus — BR-204
    // ====================================================================

    #[Test]
    public function only_an_active_route_is_serviceable(): void
    {
        $this->assertTrue(RouteStatus::ACTIVE->isServiceable());
        $this->assertFalse(RouteStatus::INACTIVE->isServiceable());
        $this->assertFalse(RouteStatus::MAINTENANCE->isServiceable());
    }

    // ====================================================================
    // StudentStatus — BR-151
    // ====================================================================

    #[Test]
    public function only_an_active_student_is_eligible_for_transport(): void
    {
        $this->assertTrue(StudentStatus::ACTIVE->isEligibleForTransport());
        $this->assertFalse(StudentStatus::INACTIVE->isEligibleForTransport());
        $this->assertFalse(StudentStatus::SUSPENDED->isEligibleForTransport());
    }

    // ====================================================================
    // StopType — BR-154
    // ====================================================================

    #[Test]
    public function stop_types_permit_the_right_operations(): void
    {
        $this->assertTrue(StopType::PICKUP->allowsPickup());
        $this->assertFalse(StopType::PICKUP->allowsDropoff());

        $this->assertFalse(StopType::DROPOFF->allowsPickup());
        $this->assertTrue(StopType::DROPOFF->allowsDropoff());

        $this->assertTrue(StopType::BOTH->allowsPickup());
        $this->assertTrue(StopType::BOTH->allowsDropoff());
    }

    // ====================================================================
    // DayOfWeek
    // ====================================================================

    #[Test]
    public function it_derives_the_day_from_a_date(): void
    {
        $this->assertSame(DayOfWeek::MONDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-03')));
        $this->assertSame(DayOfWeek::FRIDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-07')));
        $this->assertSame(DayOfWeek::SATURDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-08')));
        $this->assertSame(DayOfWeek::SUNDAY, DayOfWeek::fromDate(Carbon::parse('2026-08-09')));
    }

    #[Test]
    public function it_identifies_weekend_days(): void
    {
        $this->assertTrue(DayOfWeek::SATURDAY->isWeekend());
        $this->assertTrue(DayOfWeek::SUNDAY->isWeekend());

        foreach ([DayOfWeek::MONDAY, DayOfWeek::TUESDAY, DayOfWeek::WEDNESDAY, DayOfWeek::THURSDAY, DayOfWeek::FRIDAY] as $day) {
            $this->assertFalse($day->isWeekend(), "{$day->value} must not be a weekend");
        }
    }

    // ====================================================================
    // ScheduleFrequency
    // ====================================================================

    #[Test]
    public function frequency_governs_which_days_are_covered(): void
    {
        $this->assertTrue(ScheduleFrequency::DAILY->coversDay(DayOfWeek::MONDAY));
        $this->assertTrue(ScheduleFrequency::DAILY->coversDay(DayOfWeek::SUNDAY));

        $this->assertTrue(ScheduleFrequency::WEEKDAYS->coversDay(DayOfWeek::MONDAY));
        $this->assertFalse(ScheduleFrequency::WEEKDAYS->coversDay(DayOfWeek::SATURDAY));

        $this->assertFalse(ScheduleFrequency::WEEKENDS->coversDay(DayOfWeek::MONDAY));
        $this->assertTrue(ScheduleFrequency::WEEKENDS->coversDay(DayOfWeek::SUNDAY));

        $this->assertTrue(ScheduleFrequency::ONCE->coversDay(DayOfWeek::WEDNESDAY));
    }
}
