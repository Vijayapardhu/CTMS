<?php

namespace Tests\Unit\Models;

use App\Models\RouteStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Geofence geometry (BR-308 depends on this).
 *
 * Tested directly because an error here is invisible in the API — a wrong
 * radius simply means the "bus approaching" notification fires at the wrong
 * moment, or never.
 */
class RouteStopTest extends TestCase
{
    use RefreshDatabase;

    /** Bengaluru city centre, used as a fixed reference point. */
    private const LAT = 12.9716;

    private const LNG = 77.5946;

    private function stop(): RouteStop
    {
        return RouteStop::factory()->make([
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);
    }

    #[Test]
    public function the_distance_to_its_own_position_is_zero(): void
    {
        $this->assertSame(0.0, round($this->stop()->distanceInMetresTo(self::LAT, self::LNG), 6));
    }

    #[Test]
    public function it_measures_a_known_distance_accurately(): void
    {
        // 0.01 degrees of latitude is approximately 1,111 metres.
        $distance = $this->stop()->distanceInMetresTo(self::LAT + 0.01, self::LNG);

        $this->assertGreaterThan(1050, $distance);
        $this->assertLessThan(1170, $distance);
    }

    #[Test]
    public function distance_is_symmetric(): void
    {
        $stop = $this->stop();

        $there = $stop->distanceInMetresTo(self::LAT + 0.02, self::LNG + 0.02);

        $other = RouteStop::factory()->make([
            'latitude' => self::LAT + 0.02,
            'longitude' => self::LNG + 0.02,
        ]);

        $back = $other->distanceInMetresTo(self::LAT, self::LNG);

        $this->assertSame(round($there, 3), round($back, 3));
    }

    #[Test]
    public function a_position_inside_the_geofence_is_detected(): void
    {
        // ~11 metres away, well inside the default 100m radius.
        $this->assertTrue($this->stop()->isWithinGeofence(self::LAT + 0.0001, self::LNG));
    }

    #[Test]
    public function a_position_outside_the_geofence_is_rejected(): void
    {
        // ~1.1km away.
        $this->assertFalse($this->stop()->isWithinGeofence(self::LAT + 0.01, self::LNG));
    }

    #[Test]
    public function the_geofence_radius_can_be_widened_per_call(): void
    {
        $stop = $this->stop();

        $this->assertFalse($stop->isWithinGeofence(self::LAT + 0.01, self::LNG));
        $this->assertTrue($stop->isWithinGeofence(self::LAT + 0.01, self::LNG, 2000));
    }

    #[Test]
    public function the_geofence_radius_defaults_to_configuration(): void
    {
        config(['ctms.gps.geofence_radius_meters' => 2000]);

        $this->assertTrue($this->stop()->isWithinGeofence(self::LAT + 0.01, self::LNG));
    }

    #[Test]
    public function antipodal_distance_does_not_produce_a_calculation_error(): void
    {
        // Guards the asin() domain: floating-point drift can push the argument
        // above 1.0 for near-antipodal points and yield NAN.
        $distance = $this->stop()->distanceInMetresTo(-self::LAT, self::LNG - 180);

        $this->assertFalse(is_nan($distance));
        $this->assertGreaterThan(19_000_000, $distance);
    }
}
