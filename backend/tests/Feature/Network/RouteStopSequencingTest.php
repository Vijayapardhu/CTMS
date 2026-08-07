<?php

namespace Tests\Feature\Network;

use App\Models\Route;
use App\Models\RouteStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-05 — route stop management.
 *
 * The invariant under test is BR-200: a route's stops form a contiguous
 * 1..N sequence with no gaps and no duplicates. Every downstream calculation —
 * ETA, next stop, geofence ordering — assumes it holds.
 *
 * The resequencing implementation parks a row at sequence -1 while reordering
 * to avoid colliding with the unique (route_id, sequence_number) index, so
 * these tests exercise insert, move and delete from every direction.
 */
class RouteStopSequencingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function stopPayload(array $overrides = []): array
    {
        return array_merge([
            'stop_name' => 'Gandhi Nagar',
            'latitude' => 12.9716,
            'longitude' => 77.5946,
            'address' => '12 Gandhi Nagar Main Road',
            'landmark' => 'Opposite the post office',
            'distance_from_start_km' => 4,
            'estimated_arrival_minutes' => 12,
            'stop_type' => 'BOTH',
        ], $overrides);
    }

    /**
     * Assert the route's stops are exactly 1..N with no gaps or duplicates.
     */
    private function assertSequenceIsContiguous(Route $route): void
    {
        $sequences = $route->stops()->pluck('sequence_number')->all();

        $this->assertSame(
            range(1, count($sequences)),
            $sequences,
            'Stop sequence must be contiguous 1..N (BR-200)',
        );
    }

    /**
     * @return array<int, string> Stop names in running order.
     */
    private function stopNames(Route $route): array
    {
        return $route->stops()->pluck('stop_name')->all();
    }

    private function routeWithStops(int $count): Route
    {
        $route = Route::factory()->create();

        for ($i = 1; $i <= $count; $i++) {
            RouteStop::factory()->for($route)->atSequence($i)->create(['stop_name' => "Stop {$i}"]);
        }

        $route->syncStopCount();

        return $route->fresh();
    }

    // ====================================================================
    // READING
    // ====================================================================

    #[Test]
    public function stops_are_returned_in_running_order(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(4);

        $response = $this->getJson("/api/v1/routes/{$route->id}/stops", $this->authHeader($admin))
            ->assertOk();

        $this->assertSame(['Stop 1', 'Stop 2', 'Stop 3', 'Stop 4'],
            array_column($response->json('data'), 'stop_name'));
    }

    #[Test]
    public function any_authenticated_user_can_read_a_routes_stops(): void
    {
        $route = $this->routeWithStops(2);

        foreach ([$this->createAdmin(), $this->createDriver(), $this->createStudent()] as $user) {
            $this->getJson("/api/v1/routes/{$route->id}/stops", $this->authHeader($user))->assertOk();
        }
    }

    #[Test]
    public function reading_stops_requires_authentication(): void
    {
        $route = $this->routeWithStops(1);

        $this->getJson("/api/v1/routes/{$route->id}/stops")->assertStatus(401);
    }

    // ====================================================================
    // APPENDING
    // ====================================================================

    #[Test]
    public function the_first_stop_on_a_route_takes_sequence_one(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->postJson("/api/v1/routes/{$route->id}/stops", $this->stopPayload(),
            $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_number', 1);

        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stop_with_no_position_is_appended_to_the_end(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);

        $this->postJson("/api/v1/routes/{$route->id}/stops", $this->stopPayload(['stop_name' => 'Appended']),
            $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_number', 4);

        $this->assertSame(['Stop 1', 'Stop 2', 'Stop 3', 'Appended'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function adding_a_stop_updates_the_route_stop_count(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create(['number_of_stops' => 0]);

        $this->postJson("/api/v1/routes/{$route->id}/stops", $this->stopPayload(),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertSame(1, $route->fresh()->number_of_stops);
    }

    // ====================================================================
    // INSERTING
    // ====================================================================

    #[Test]
    public function a_stop_can_be_inserted_at_the_front(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);

        $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['stop_name' => 'New First', 'sequence_number' => 1]),
            $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_number', 1);

        $this->assertSame(['New First', 'Stop 1', 'Stop 2', 'Stop 3'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stop_can_be_inserted_in_the_middle(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(4);

        $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['stop_name' => 'Inserted', 'sequence_number' => 3]),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertSame(['Stop 1', 'Stop 2', 'Inserted', 'Stop 3', 'Stop 4'],
            $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_position_beyond_the_end_appends_rather_than_leaving_a_gap(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(2);

        // Asking for position 50 on a 2-stop route must not create a gap.
        $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['stop_name' => 'Clamped', 'sequence_number' => 50]),
            $this->authHeader($admin))
            ->assertStatus(201)
            ->assertJsonPath('data.sequence_number', 3);

        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function inserting_repeatedly_at_the_front_keeps_the_sequence_contiguous(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        foreach (['C', 'B', 'A'] as $name) {
            $this->postJson("/api/v1/routes/{$route->id}/stops",
                $this->stopPayload(['stop_name' => $name, 'sequence_number' => 1]),
                $this->authHeader($admin))->assertStatus(201);
        }

        $this->assertSame(['A', 'B', 'C'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    // ====================================================================
    // MOVING
    // ====================================================================

    #[Test]
    public function a_stop_can_be_moved_earlier(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(5);
        $stop = $route->stops()->where('sequence_number', 4)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['sequence_number' => 2],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 1', 'Stop 4', 'Stop 2', 'Stop 3', 'Stop 5'],
            $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stop_can_be_moved_later(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(5);
        $stop = $route->stops()->where('sequence_number', 2)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['sequence_number' => 4],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 1', 'Stop 3', 'Stop 4', 'Stop 2', 'Stop 5'],
            $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stop_can_be_moved_from_first_to_last(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(4);
        $stop = $route->stops()->where('sequence_number', 1)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['sequence_number' => 4],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 2', 'Stop 3', 'Stop 4', 'Stop 1'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stop_can_be_moved_from_last_to_first(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(4);
        $stop = $route->stops()->where('sequence_number', 4)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['sequence_number' => 1],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 4', 'Stop 1', 'Stop 2', 'Stop 3'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function moving_a_stop_to_its_current_position_changes_nothing(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->where('sequence_number', 2)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['sequence_number' => 2],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 1', 'Stop 2', 'Stop 3'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_move_beyond_the_end_clamps_to_the_last_position(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->where('sequence_number', 1)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['sequence_number' => 99],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 2', 'Stop 3', 'Stop 1'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stops_attributes_can_be_edited_without_moving_it(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->where('sequence_number', 2)->first();

        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [
            'stop_name' => 'Renamed',
            'waiting_time_minutes' => 10,
        ], $this->authHeader($admin))->assertOk();

        $stop->refresh();

        $this->assertSame('Renamed', $stop->stop_name);
        $this->assertSame(2, $stop->sequence_number);
        $this->assertSame(10, $stop->waiting_time_minutes);
    }

    // ====================================================================
    // DELETING — BR-201, BR-202
    // ====================================================================

    #[Test]
    public function deleting_a_middle_stop_closes_the_gap(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(5);
        $stop = $route->stops()->where('sequence_number', 3)->first();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 1', 'Stop 2', 'Stop 4', 'Stop 5'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function deleting_the_first_stop_closes_the_gap(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->where('sequence_number', 1)->first();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 2', 'Stop 3'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function deleting_the_last_stop_leaves_the_sequence_intact(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->where('sequence_number', 3)->first();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(['Stop 1', 'Stop 2'], $this->stopNames($route->fresh()));
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function deleting_a_stop_updates_the_route_stop_count(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->first();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($admin))->assertOk();

        $this->assertSame(2, $route->fresh()->number_of_stops);
    }

    #[Test]
    public function a_stop_with_assigned_students_cannot_be_deleted(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(3);
        $stop = $route->stops()->where('sequence_number', 2)->first();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $route->id,
            'pickup_stop_id' => $stop->id,
        ])->save();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($admin))
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('route_stops', ['id' => $stop->id, 'deleted_at' => null]);
        $this->assertSequenceIsContiguous($route->fresh());
    }

    #[Test]
    public function a_stop_used_only_for_drop_off_also_blocks_deletion(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(2);
        $stop = $route->stops()->where('sequence_number', 2)->first();

        $student = $this->createStudent();
        $student->student->forceFill([
            'route_id' => $route->id,
            'dropoff_stop_id' => $stop->id,
        ])->save();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($admin))->assertStatus(409);
    }

    // ====================================================================
    // CROSS-ROUTE ISOLATION — BR-216
    // ====================================================================

    #[Test]
    public function a_stop_cannot_be_edited_through_a_different_route(): void
    {
        $admin = $this->createAdmin();
        $routeA = $this->routeWithStops(2);
        $routeB = $this->routeWithStops(2);
        $stopOnB = $routeB->stops()->first();

        // Pairing route A's id with route B's stop must not reach the stop.
        $this->putJson("/api/v1/routes/{$routeA->id}/stops/{$stopOnB->id}", ['stop_name' => 'Hijacked'],
            $this->authHeader($admin))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Stop not found on this route.');

        $this->assertNotSame('Hijacked', $stopOnB->fresh()->stop_name);
    }

    #[Test]
    public function a_stop_cannot_be_deleted_through_a_different_route(): void
    {
        $admin = $this->createAdmin();
        $routeA = $this->routeWithStops(2);
        $routeB = $this->routeWithStops(2);
        $stopOnB = $routeB->stops()->first();

        $this->deleteJson("/api/v1/routes/{$routeA->id}/stops/{$stopOnB->id}", [],
            $this->authHeader($admin))->assertStatus(404);

        $this->assertDatabaseHas('route_stops', ['id' => $stopOnB->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_stop_cannot_be_attached_to_a_route_by_payload(): void
    {
        $admin = $this->createAdmin();
        $routeA = Route::factory()->create();
        $routeB = Route::factory()->create();

        // route_id in the body must be ignored; the URL is authoritative.
        $this->postJson("/api/v1/routes/{$routeA->id}/stops",
            $this->stopPayload(['route_id' => $routeB->id]),
            $this->authHeader($admin))->assertStatus(201);

        $this->assertSame(1, $routeA->fresh()->number_of_stops);
        $this->assertSame(0, $routeB->fresh()->number_of_stops);
    }

    // ====================================================================
    // AUTHORIZATION
    // ====================================================================

    #[Test]
    public function a_driver_cannot_add_a_stop(): void
    {
        $driver = $this->createDriver();
        $route = Route::factory()->create();

        $this->postJson("/api/v1/routes/{$route->id}/stops", $this->stopPayload(),
            $this->authHeader($driver))->assertStatus(403);

        $this->assertDatabaseCount('route_stops', 0);
    }

    #[Test]
    public function a_student_cannot_delete_a_stop(): void
    {
        $student = $this->createStudent();
        $route = $this->routeWithStops(2);
        $stop = $route->stops()->first();

        $this->deleteJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", [],
            $this->authHeader($student))->assertStatus(403);

        $this->assertDatabaseHas('route_stops', ['id' => $stop->id, 'deleted_at' => null]);
    }

    #[Test]
    public function adding_a_stop_requires_authentication(): void
    {
        $route = Route::factory()->create();

        $this->postJson("/api/v1/routes/{$route->id}/stops", $this->stopPayload())->assertStatus(401);
    }

    // ====================================================================
    // VALIDATION — BR-214
    // ====================================================================

    #[Test]
    public function it_validates_the_stop_payload(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->postJson("/api/v1/routes/{$route->id}/stops", [], $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'stop_name', 'latitude', 'longitude', 'address',
                    'distance_from_start_km', 'estimated_arrival_minutes',
                ],
            ]);
    }

    #[Test]
    public function it_rejects_coordinates_outside_the_valid_range(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['latitude' => 200]), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['latitude']]);
    }

    #[Test]
    public function it_rejects_coordinates_outside_the_service_area(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        // Valid coordinates, but in the Atlantic.
        $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['latitude' => 0.0, 'longitude' => 0.0]),
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['latitude']]);
    }

    #[Test]
    public function it_detects_transposed_coordinates(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        // Latitude and longitude the wrong way round — both individually valid.
        $response = $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['latitude' => 77.5946, 'longitude' => 12.9716]),
            $this->authHeader($admin))->assertStatus(422);

        $this->assertStringContainsString('wrong way round', $response->json('errors.latitude.0'));
    }

    #[Test]
    public function a_partial_update_cannot_walk_a_stop_outside_the_service_area(): void
    {
        $admin = $this->createAdmin();
        $route = $this->routeWithStops(1);
        $stop = $route->stops()->first();

        // Supplying only latitude must still be checked against the stored
        // longitude, not skipped because the pair is incomplete.
        $this->putJson("/api/v1/routes/{$route->id}/stops/{$stop->id}", ['latitude' => 0.0],
            $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['latitude']]);
    }

    #[Test]
    public function an_unknown_stop_type_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $this->postJson("/api/v1/routes/{$route->id}/stops",
            $this->stopPayload(['stop_type' => 'HALT']), $this->authHeader($admin))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['stop_type']]);
    }

    #[Test]
    public function adding_a_stop_to_an_unknown_route_returns_404(): void
    {
        $admin = $this->createAdmin();

        $this->postJson('/api/v1/routes/019fd73c-0000-7000-8000-000000000000/stops',
            $this->stopPayload(), $this->authHeader($admin))->assertStatus(404);
    }

    #[Test]
    public function adding_a_stop_writes_an_audit_record(): void
    {
        $admin = $this->createAdmin();
        $route = Route::factory()->create();

        $id = $this->postJson("/api/v1/routes/{$route->id}/stops", $this->stopPayload(),
            $this->authHeader($admin))->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'table_name' => 'route_stops',
            'record_id' => $id,
        ]);
    }
}
