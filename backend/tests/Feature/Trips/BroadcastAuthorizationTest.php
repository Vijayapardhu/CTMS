<?php

namespace Tests\Feature\Trips;

use App\Enums\TripStatus;
use App\Models\Route;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BR-304 — broadcast channel authorization.
 *
 * Shared real-time infrastructure. The rule this defends: live position of a
 * bus carrying minors is not public within the institution, and a subscription
 * that was valid when the trip started does not survive the trip ending or the
 * subscriber losing entitlement.
 *
 * Authorization callbacks are invoked directly, which is what Laravel's
 * broadcasting auth endpoint does on subscribe and again on every reconnect.
 */
class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the registered channel callback exactly as the auth endpoint would.
     */
    private function authorize(string $channel, User $user, array $parameters = []): bool
    {
        foreach (Broadcast::getChannels() as $pattern => $callback) {
            $regex = '#^'.preg_replace('/\{[^}]+\}/', '([^.]+)', $pattern).'$#';

            if (preg_match($regex, $channel, $matches)) {
                array_shift($matches);

                return (bool) $callback($user, ...$matches);
            }
        }

        return false;
    }

    private function runningTripOnRoute(Route $route): Trip
    {
        return Trip::factory()->running()->create(['route_id' => $route->id]);
    }

    // ====================================================================
    // TRIP CHANNEL
    // ====================================================================

    #[Test]
    public function an_admin_can_subscribe_to_any_running_trip(): void
    {
        $admin = $this->createAdmin();
        $trip = $this->runningTripOnRoute(Route::factory()->withStops()->create());

        $this->assertTrue($this->authorize("trips.{$trip->id}", $admin));
    }

    #[Test]
    public function the_assigned_driver_can_subscribe(): void
    {
        $driver = $this->createDriver();
        $trip = Trip::factory()->running()->create(['driver_id' => $driver->driver->id]);

        $this->assertTrue($this->authorize("trips.{$trip->id}", $driver));
    }

    #[Test]
    public function another_driver_cannot_subscribe(): void
    {
        $alice = $this->createDriver();
        $bob = $this->createDriver();
        $trip = Trip::factory()->running()->create(['driver_id' => $bob->driver->id]);

        $this->assertFalse($this->authorize("trips.{$trip->id}", $alice));
    }

    #[Test]
    public function a_student_on_the_route_can_subscribe(): void
    {
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $route->id])->save();

        $trip = $this->runningTripOnRoute($route);

        $this->assertTrue($this->authorize("trips.{$trip->id}", $student->fresh()));
    }

    #[Test]
    public function a_student_on_another_route_cannot_subscribe(): void
    {
        $route = Route::factory()->withStops()->create();
        $otherRoute = Route::factory()->withStops()->create();

        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $otherRoute->id])->save();

        $trip = $this->runningTripOnRoute($route);

        // Live position of a bus carrying other people's children is not
        // public within the institution.
        $this->assertFalse($this->authorize("trips.{$trip->id}", $student->fresh()));
    }

    #[Test]
    public function an_unassigned_student_cannot_subscribe(): void
    {
        $student = $this->createStudent();
        $trip = $this->runningTripOnRoute(Route::factory()->withStops()->create());

        $this->assertFalse($this->authorize("trips.{$trip->id}", $student));
    }

    #[Test]
    public function a_student_whose_pass_lapsed_cannot_subscribe(): void
    {
        $route = Route::factory()->withStops()->create();

        $student = $this->createStudent(profileAttributes: [
            'has_valid_ticket' => false,
            'ticket_expiry_date' => null,
        ]);
        $student->student->forceFill(['route_id' => $route->id])->save();

        $trip = $this->runningTripOnRoute($route);

        // Entitlement is re-evaluated on every reconnect, so a lapse mid-term
        // stops the stream rather than persisting until the token expires.
        $this->assertFalse($this->authorize("trips.{$trip->id}", $student->fresh()));
    }

    // ====================================================================
    // THE TRIP WINDOW
    // ====================================================================

    #[Test]
    public function nobody_can_subscribe_to_a_scheduled_trip(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->create(); // SCHEDULED

        $this->assertFalse($this->authorize("trips.{$trip->id}", $admin));
    }

    #[Test]
    public function nobody_can_subscribe_to_a_completed_trip(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->completed()->create();

        // The window is the trip, not the account.
        $this->assertFalse($this->authorize("trips.{$trip->id}", $admin));
    }

    #[Test]
    public function nobody_can_subscribe_to_a_cancelled_trip(): void
    {
        $admin = $this->createAdmin();
        $trip = Trip::factory()->cancelled()->create();

        $this->assertFalse($this->authorize("trips.{$trip->id}", $admin));
    }

    #[Test]
    public function a_subscription_dies_when_the_trip_ends(): void
    {
        $route = Route::factory()->withStops()->create();
        $student = $this->createStudent();
        $student->student->forceFill(['route_id' => $route->id])->save();

        $trip = $this->runningTripOnRoute($route);

        $this->assertTrue($this->authorize("trips.{$trip->id}", $student->fresh()));

        $trip->forceFill(['status' => TripStatus::COMPLETED])->save();

        // The reconnect after the trip ends is refused.
        $this->assertFalse($this->authorize("trips.{$trip->id}", $student->fresh()));
    }

    #[Test]
    public function an_unknown_trip_cannot_be_subscribed_to(): void
    {
        $admin = $this->createAdmin();

        $this->assertFalse($this->authorize('trips.019fd73c-0000-7000-8000-000000000000', $admin));
    }

    // ====================================================================
    // FLEET AND USER CHANNELS
    // ====================================================================

    #[Test]
    public function only_an_admin_can_subscribe_to_the_fleet_channel(): void
    {
        $this->assertTrue($this->authorize('fleet', $this->createAdmin()));
        $this->assertFalse($this->authorize('fleet', $this->createDriver()));
        $this->assertFalse($this->authorize('fleet', $this->createStudent()));
    }

    #[Test]
    public function a_user_can_subscribe_to_their_own_stream(): void
    {
        $user = $this->createStudent();

        $this->assertTrue($this->authorize("users.{$user->id}", $user));
    }

    #[Test]
    public function a_user_cannot_subscribe_to_another_users_stream(): void
    {
        $alice = $this->createStudent();
        $bob = $this->createStudent();

        $this->assertFalse($this->authorize("users.{$bob->id}", $alice));
    }

    #[Test]
    public function an_admin_cannot_subscribe_to_another_users_stream(): void
    {
        $admin = $this->createAdmin();
        $student = $this->createStudent();

        // No privilege level reads another person's notification stream.
        $this->assertFalse($this->authorize("users.{$student->id}", $admin));
    }
}
