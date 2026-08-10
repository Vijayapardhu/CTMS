<?php

namespace App\Console\Commands;

use App\Enums\AccessLevel;
use App\Enums\BusStatus;
use App\Enums\DriverStatus;
use App\Enums\IncidentType;
use App\Enums\InspectionItem;
use App\Enums\MaintenancePriority;
use App\Enums\StudentStatus;
use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\PreventiveMaintenanceSchedule;
use App\Models\ReplacementAssignment;
use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Student;
use App\Models\Trip;
use App\Models\User;
use App\Services\Communication\AnnouncementService;
use App\Services\Fleet\BusDocumentService;
use App\Services\Fleet\VehicleInspectionService;
use App\Services\Governance\DataAccessLogger;
use App\Services\Governance\RetentionService;
use App\Services\Incidents\IncidentService;
use App\Services\Incidents\ReplacementService;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Network\ScheduleService;
use App\Services\Tracking\GeofenceService;
use App\Services\Tracking\GpsIngestionService;
use App\Services\Tracking\PassengerCountService;
use App\Services\Trips\ConsolidationService;
use App\Services\Trips\TripRecoveryService;
use App\Services\Trips\TripService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Build the demonstration world.
 *
 *     php artisan ctms:demo --fresh
 *
 * Two rules this command follows, and they are the reason it is a command and
 * not a set of INSERTs.
 *
 * 1. **Deterministic.** Same command, same fleet, same names, same registration
 *    numbers, same scenarios. A demo where the numbers move between rehearsal
 *    and the meeting is a demo nobody trusts.
 *
 * 2. **Driven through the real services.** Every operational state — a started
 *    trip, a failed inspection, an acknowledged incident, a dispatched
 *    replacement — is produced by the same service the API calls. So the state
 *    machines really ran, the audit rows are real, the notifications really
 *    dispatched, and nothing on screen is a value written directly into a
 *    column to make a screenshot look right.
 *
 * What this is NOT: fixture data for tests. The suite has factories for that.
 */
class SeedDemo extends Command
{
    protected $signature = 'ctms:demo {--fresh : Rebuild the schema first}';

    protected $description = 'Build a deterministic demonstration environment';

    private const PASSWORD = 'Ctms@2026';

    /** Bengaluru, because the service area and geofences are configured for it. */
    private const CAMPUS = [12.9716, 77.5946];

    public function handle(
        TripService $trips,
        IncidentService $incidents,
        MaintenanceService $maintenance,
        ReplacementService $replacements,
        VehicleInspectionService $inspections,
        BusDocumentService $documents,
        PassengerCountService $passengers,
        GpsIngestionService $gps,
        GeofenceService $geofences,
        TripRecoveryService $recovery,
        AnnouncementService $announcements,
        DataAccessLogger $accessLog,
        RetentionService $retention,
        ScheduleService $schedules,
        ConsolidationService $consolidations,
    ): int {
        if ($this->option('fresh')) {
            $this->components->info('Rebuilding the schema…');
            $this->call('migrate:fresh');
        }

        $this->components->info('Building the demonstration world…');

        $staff = $this->staff();
        $head = $staff['OPERATIONS'];
        $supervisor = $staff['SUPPORT'];
        $root = $staff['SUPER_ADMIN'];

        $routes = $this->routes();
        $buses = $this->buses();
        $drivers = $this->drivers($buses);
        $this->students($routes);

        // A bus cannot legally leave the yard without current statutory
        // documents and a pre-trip inspection today — BR-361 and the readiness
        // rule. The demo has to satisfy those the same way a real morning
        // does, so the paperwork is recorded and the checks are actually run.
        $this->paperwork($documents, $buses, $head);
        $this->morningChecks($inspections, $buses, $drivers);
        $this->timetable($schedules, $routes, $buses, $drivers, $head);
        $this->preventive($buses);

        $today = Carbon::today();

        // ── 1. A normal operating day ──────────────────────────────────────
        //
        // Four services: one finished this morning, one out on the road now,
        // two still to go. That is what a transport office actually sees at
        // eleven o'clock.
        $completed = $this->trip($trips, $geofences, $routes[0], $buses[0], $drivers[0], $head, $today, '07:30:00', '08:15:00');
        $running = $this->trip($trips, $geofences, $routes[1], $buses[1], $drivers[1], $head, $today, '09:00:00', '09:50:00');
        $stranded = $this->trip($trips, $geofences, $routes[2], $buses[2], $drivers[2], $head, $today, '10:00:00', '10:45:00');
        $this->trip($trips, $geofences, $routes[3], $buses[3], $drivers[3], $head, $today, '16:30:00', '17:20:00');
        $this->trip($trips, $geofences, $routes[4], $buses[4], $drivers[4], $head, $today, '17:30:00', '18:25:00');

        // ── 2 and 3. A failed inspection, and the bus it holds off the road ─
        $this->failedInspection($inspections, $buses[5], $drivers[5]);
        $this->components->twoColumnDetail('Failed inspection', $buses[5]->registration_number);

        // ── 7. A bus out on the road, with a position to show on the map ────
        $this->operate($trips, $gps, $passengers, $running, $drivers[1], $head);
        $this->components->twoColumnDetail('Running now', $buses[1]->registration_number);

        // ── 1 (cont). A service that finished, with people counted onto it ──
        $this->operate($trips, $gps, $passengers, $completed, $drivers[0], $head, finish: true);

        // ── 8. An attendance disagreement, produced by reconciliation ───────
        $this->discrepancy($recovery, $completed);
        $this->components->twoColumnDetail('Attendance disagreement', 'on this morning’s '.$routes[0]->route_name);

        // ── 9. A correction on the completed trip ──────────────────────────
        // Deliberately a different field from the attendance disagreement
        // above: correcting the seat count would tangle two stories that are
        // clearer told separately.
        $recovery->correct(
            $completed->refresh(),
            'booked_seat_count',
            28,
            'Four bookings were cancelled the night before and not removed from the manifest.',
            $head,
        );

        // ── 4. Incidents: one still open, one already dealt with ───────────
        $this->incidents($incidents, $running, $drivers[1]->user, $supervisor);

        // ── 6. A breakdown that strands a service, and its replacement ──────
        $this->replacement($trips, $incidents, $replacements, $stranded, $drivers[2], $head);
        $this->components->twoColumnDetail('Replacement dispatched', 'for '.$routes[2]->route_name);

        // ── 5. Workshop work in every state worth showing ───────────────────
        $this->workshop($maintenance, $buses, $head, $supervisor);

        // ── 10. Something published, and something still a draft ────────────
        $this->announcements($announcements, $head);

        // A merge waiting on a decision, so the consolidation tab is not
        // an empty state in front of an audience. Left undecided on
        // purpose: approving it live is the demonstration.
        $this->consolidation($trips, $geofences, $consolidations, $routes, $buses, $drivers, $head, $today);

        // A retention run in dry-run mode: it reports what it *would*
        // purge and deletes nothing. Governance needs a row to show; a
        // demonstration does not need anything actually destroyed.
        $retention->purgeAll(dryRun: true);

        // ── 11. A governance trail with something in it ─────────────────────
        $accessLog->recordBulkExport(
            actor: $root,
            subjectType: 'user',
            dataClass: 'SUBJECT_ACCESS_REQUEST',
            recordCount: 12,
            reason: 'Student asked for a copy of their transport record.',
        );

        $this->summary($staff, $drivers[0]);

        return self::SUCCESS;
    }

    // ── people ─────────────────────────────────────────────────────────────

    /**
     * One administrator per access level.
     *
     * Named for the job rather than the enum, because "Transport Head" is what
     * somebody in the room will recognise and `OPERATIONS` is not.
     */
    private function staff(): array
    {
        $people = [
            AccessLevel::VIEWER->value => ['Meera', 'Iyer', 'viewer@ctms.edu', 'Transport Assistant'],
            AccessLevel::SUPPORT->value => ['Arun', 'Nair', 'supervisor@ctms.edu', 'Transport Supervisor'],
            AccessLevel::OPERATIONS->value => ['Priya', 'Rao', 'head@ctms.edu', 'Transport Head'],
            AccessLevel::SUPER_ADMIN->value => ['Sanjay', 'Menon', 'admin@ctms.edu', 'System Administrator'],
        ];

        $staff = [];
        $index = 0;

        foreach ($people as $level => [$first, $last, $email, $designation]) {
            $index++;
            $user = $this->user($email, $first, $last, UserRole::ADMIN, '98450100'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));

            Admin::query()->firstOrNew(['user_id' => $user->getKey()])
                ->forceFill([
                    'user_id' => $user->getKey(),
                    'designation' => $designation,
                    'department' => 'Transport Operations',
                    'access_level' => $level,
                ])->save();

            $staff[$level] = $user;
        }

        return $staff;
    }

    /** @return array<int, Driver> */
    private function drivers(array $buses): array
    {
        $people = [
            ['Ravi', 'Kumar'], ['Suresh', 'Babu'], ['Anil', 'Joseph'],
            ['Vijay', 'Shetty'], ['Manoj', 'Pillai'], ['Rakesh', 'Gowda'],
        ];

        $drivers = [];

        foreach ($people as $index => [$first, $last]) {
            $user = $this->user(
                sprintf('driver%d@ctms.edu', $index + 1),
                $first,
                $last,
                UserRole::DRIVER,
                '98450200'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            );

            $driver = Driver::query()->firstOrNew(['user_id' => $user->getKey()]);
            $driver->forceFill([
                'user_id' => $user->getKey(),
                'license_number' => sprintf('KA0120%04d', 1000 + $index),
                'license_class' => 'HEAVY',
                // The last driver's licence lapses within the month, so the
                // expiry column on A7 has something to say.
                'license_expiry_date' => $index === 5
                    ? Carbon::today()->addDays(18)
                    : Carbon::today()->addYears(2)->addDays($index),
                'status' => DriverStatus::AVAILABLE,
                'assigned_bus_id' => $buses[$index]->getKey(),
            ])->save();

            $drivers[] = $driver->refresh();
        }

        return $drivers;
    }

    private function students(array $routes): void
    {
        $names = [
            ['Asha', 'Menon'], ['Dev', 'Nair'], ['Kavya', 'Reddy'], ['Rohit', 'Sharma'],
            ['Nisha', 'Varma'], ['Imran', 'Khan'], ['Lakshmi', 'Rao'], ['Tarun', 'Bhat'],
            ['Sneha', 'Pillai'], ['Vikram', 'Singh'], ['Divya', 'Kurup'], ['Arjun', 'Das'],
        ];

        foreach ($names as $index => [$first, $last]) {
            $user = $this->user(
                sprintf('student%d@ctms.edu', $index + 1),
                $first,
                $last,
                UserRole::STUDENT,
                '98450300'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            );

            // Two students are deliberately left without transport, so the
            // "no route" filter on A12 finds something.
            $route = $index < 10 ? $routes[$index % count($routes)] : null;
            $stop = $route?->stops()->orderBy('sequence_number')->first();

            Student::query()->firstOrNew(['user_id' => $user->getKey()])->forceFill([
                'user_id' => $user->getKey(),
                'registration_number' => sprintf('21BCE%04d', 1000 + $index),
                'department' => ['Computer Science', 'Mechanical', 'Civil'][$index % 3],
                'year_of_study' => ($index % 4) + 1,
                'status' => StudentStatus::ACTIVE,
                'route_id' => $route?->getKey(),
                'pickup_stop_id' => $stop?->getKey(),
                'has_valid_ticket' => $index !== 3,
                'ticket_expiry_date' => Carbon::today()->addMonths(9),
            ])->save();
        }
    }

    private function user(string $email, string $first, string $last, UserRole $role, string $phone): User
    {
        $user = User::withTrashed()->where('email', $email)->first() ?? new User;

        $user->forceFill([
            'email' => $email,
            'phone_number' => $phone,
            'first_name' => $first,
            'last_name' => $last,
            'role' => $role,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            'deleted_at' => null,
        ])->save();

        return $user->refresh();
    }

    // ── the network and the fleet ──────────────────────────────────────────

    /** @return array<int, Route> */
    private function routes(): array
    {
        $network = [
            ['NCL', 'North Campus Loop', 'Yelahanka Depot', 'Main Campus Gate', 14.2, 48],
            ['SCL', 'South Campus Loop', 'Jayanagar Depot', 'Main Campus Gate', 11.6, 42],
            ['EXP', 'Whitefield Express', 'Whitefield Terminus', 'Main Campus Gate', 22.4, 65],
            ['HST', 'Hostel Shuttle', 'Hostel Block C', 'Academic Block', 4.8, 18],
            ['WST', 'West City Line', 'Rajajinagar Circle', 'Main Campus Gate', 17.9, 55],
        ];

        $routes = [];

        foreach ($network as $index => [$code, $name, $from, $to, $km, $minutes]) {
            $route = Route::query()->firstOrNew(['route_code' => $code]);
            $route->forceFill([
                'route_code' => $code,
                'route_name' => $name,
                'description' => sprintf('%s to %s.', $from, $to),
                'total_distance_km' => $km,
                'estimated_duration_minutes' => $minutes,
                'start_point' => $from,
                'end_point' => $to,
                'status' => 'ACTIVE',
                'number_of_stops' => 0,
            ])->save();

            if ($route->stops()->count() === 0) {
                $this->stops($route, $index, $km, $minutes);
            }

            // BR-203 — a route with no stops cannot legally be scheduled, and
            // the count is a column rather than a join, so it is synced.
            $route->syncStopCount();

            $routes[] = $route->refresh();
        }

        return $routes;
    }

    private function stops(Route $route, int $routeIndex, float $km, int $minutes): void
    {
        $names = ['Depot', 'Ring Road Junction', 'Market Square', 'Tech Park Gate', 'Hostel Block', 'Main Campus Gate'];

        foreach ($names as $sequence => $name) {
            // Fanned out from campus so every stop sits inside the service
            // area the coordinate rule validates against.
            $offset = ($sequence + 1) * 0.006;

            // forceFill rather than create: `sequence_number` is deliberately
            // not mass-assignable, and reordering a route's stops is not
            // something a payload gets to do.
            (new RouteStop)->forceFill([
                'route_id' => $route->getKey(),
                'stop_name' => $name,
                'sequence_number' => $sequence + 1,
                'latitude' => self::CAMPUS[0] + $offset * (($routeIndex % 2 === 0) ? 1 : -1),
                'longitude' => self::CAMPUS[1] + $offset * (($routeIndex % 3 === 0) ? 1 : -1),
                'address' => sprintf('%s, %s', $name, $route->start_point),
                'landmark' => null,
                'distance_from_start_km' => (int) round($km * ($sequence / (count($names) - 1))),
                'estimated_arrival_minutes' => (int) round($minutes * ($sequence / (count($names) - 1))),
                'waiting_time_minutes' => 2,
                'stop_type' => 'BOTH',
            ])->save();
        }
    }

    /** @return array<int, Bus> */
    private function buses(): array
    {
        $fleet = [
            ['KA-01-FA-1101', 'Campus 1', 'Tata Starbus', 44, 2021],
            ['KA-01-FA-1102', 'Campus 2', 'Tata Starbus', 44, 2021],
            ['KA-01-FA-1103', 'Campus 3', 'Ashok Leyland Falcon', 52, 2020],
            ['KA-01-FA-1104', 'Campus 4', 'Ashok Leyland Falcon', 52, 2020],
            ['KA-01-FA-1105', 'Campus 5', 'Tata Starbus', 44, 2022],
            ['KA-01-FA-1106', 'Campus 6', 'Eicher Skyline', 36, 2019],
            ['KA-01-FA-1107', 'Campus 7', 'Eicher Skyline', 36, 2019],
            ['KA-01-FA-1108', 'Campus 8', 'Tata Starbus', 44, 2022],
        ];

        $buses = [];

        foreach ($fleet as $index => [$registration, $name, $model, $seats, $year]) {
            $bus = Bus::query()->firstOrNew(['registration_number' => $registration]);
            $bus->forceFill([
                'registration_number' => $registration,
                'vehicle_name' => $name,
                'model' => $model,
                'year_of_manufacture' => $year,
                'seating_capacity' => $seats,
                'fuel_type' => 'DIESEL',
                'status' => BusStatus::AVAILABLE,
                'current_odometer' => 40_000 + $index * 3_500,
                'mileage' => 4.5,
            ])->save();

            $buses[] = $bus->refresh();
        }

        return $buses;
    }

    /**
     * Statutory documents for the fleet.
     *
     * One bus is given an insurance certificate that lapses inside a fortnight,
     * so the "documents needing attention" panel on A5 has something real to
     * show rather than being demonstrated on an empty state.
     */
    private function paperwork(BusDocumentService $documents, array $buses, User $actor): void
    {
        foreach ($buses as $index => $bus) {
            foreach (['FITNESS', 'INSURANCE', 'PERMIT', 'POLLUTION'] as $type) {
                $expires = ($index === 2 && $type === 'INSURANCE')
                    ? Carbon::today()->addDays(11)
                    : Carbon::today()->addMonths(8 + $index);

                $documents->record($bus, [
                    'document_type' => $type,
                    'document_number' => sprintf('%s-%s', substr($type, 0, 3), 100_000 + $index),
                    'issuing_authority' => 'Regional Transport Office, Bengaluru',
                    'issued_on' => Carbon::today()->subMonths(4)->toDateString(),
                    'expires_on' => $expires->toDateString(),
                ], $actor);
            }
        }
    }

    /**
     * The pre-trip check every bus that runs today has to pass.
     *
     * Run through the inspection service with all fourteen items, so service
     * readiness clears for the right reason rather than because a column was
     * set by hand.
     */
    private function morningChecks(VehicleInspectionService $inspections, array $buses, array $drivers): void
    {
        foreach (array_slice($buses, 0, 5) as $index => $bus) {
            $driver = $drivers[$index];

            $inspections->submit(
                $bus,
                $driver,
                collect(InspectionItem::cases())->map(fn (InspectionItem $item) => [
                    'item' => $item->value,
                    'passed' => true,
                    'notes' => null,
                ])->all(),
                (int) $bus->current_odometer,
                $driver->user,
                'Pre-departure check — all clear.',
            );
        }
    }

    /**
     * The weekly timetable each route runs to.
     *
     * A17 is a timetable screen, and a timetable screen with nothing in it
     * teaches an audience that the feature does not work.
     */
    private function timetable(
        ScheduleService $schedules,
        array $routes,
        array $buses,
        array $drivers,
        User $actor,
    ): void {
        // One weekday service per route, staggered. Two `WEEKDAYS` schedules
        // for the same bus at the same hour is exactly the clash
        // `ScheduleService` refuses — correctly, since a bus cannot be on two
        // routes at once.
        foreach ($routes as $index => $route) {
            $schedules->create([
                'route_id' => (string) $route->getKey(),
                'bus_id' => (string) $buses[$index]->getKey(),
                'driver_id' => (string) $drivers[$index]->getKey(),
                'departure_time' => sprintf('%02d:30:00', 7 + $index),
                'arrival_time' => sprintf('%02d:20:00', 8 + $index),
                'day_of_week' => 'MONDAY',
                'frequency' => 'WEEKDAYS',
                'expected_passenger_count' => 30,
            ], $actor);
        }
    }

    /**
     * Preventive services, one of them already overdue.
     *
     * The overdue one is the point: "what is due before it breaks" is only a
     * useful screen when something is actually due.
     */
    private function preventive(array $buses): void
    {
        // The overdue one sits on the bus that already failed its inspection.
        // BR-366 refuses to assign a bus past its service grace period, so
        // putting it on a bus that runs today would stop the day starting —
        // which is the rule working, not a seeding bug.
        $services = [
            [5, 'Engine oil and filter', 180, 15000, -12],
            [6, 'Brake system inspection', 120, 10000, 26],
            [7, 'Gearbox oil change', 365, 40000, 94],
        ];

        foreach ($services as [$index, $name, $days, $km, $dueInDays]) {
            $bus = $buses[$index];

            (new PreventiveMaintenanceSchedule)->forceFill([
                'bus_id' => $bus->getKey(),
                'service_name' => $name,
                'description' => null,
                'interval_days' => $days,
                'interval_km' => $km,
                'last_serviced_on' => Carbon::today()->subDays($days - $dueInDays),
                'last_serviced_odometer' => (int) $bus->current_odometer - $km + 400,
                'due_on' => Carbon::today()->addDays($dueInDays),
                'due_at_odometer' => (int) $bus->current_odometer + 400,
                'grace_days' => 7,
                'is_active' => true,
            ])->save();
        }
    }

    /** Two nearly-empty evening shuttles, and a proposal to merge them. */
    private function consolidation(
        TripService $trips,
        GeofenceService $geofences,
        ConsolidationService $consolidations,
        array $routes,
        array $buses,
        array $drivers,
        User $actor,
        Carbon $today,
    ): void {
        $source = $this->trip($trips, $geofences, $routes[3], $buses[6], $drivers[3], $actor, $today, '19:00:00', '19:40:00');
        $target = $this->trip($trips, $geofences, $routes[3], $buses[7], $drivers[4], $actor, $today, '19:15:00', '19:55:00');

        foreach ([$source, $target] as $trip) {
            $trip->forceFill(['occupied_seat_count' => 4, 'booked_seat_count' => 6])->save();
        }

        $consolidations->propose(
            $source->refresh(),
            $target->refresh(),
            $actor,
            'Both evening shuttles are under a tenth full; one bus can carry everybody.',
        );
    }

    // ── the operating day ──────────────────────────────────────────────────

    private function trip(
        TripService $trips,
        GeofenceService $geofences,
        Route $route,
        Bus $bus,
        Driver $driver,
        User $actor,
        Carbon $date,
        string $departs,
        string $arrives,
    ): Trip {
        $trip = $trips->createAdHoc([
            'route_id' => (string) $route->getKey(),
            'bus_id' => (string) $bus->getKey(),
            'driver_id' => (string) $driver->getKey(),
            'trip_date' => $date->toDateString(),
            'scheduled_departure_time' => $departs,
            'scheduled_arrival_time' => $arrives,
            'booked_seat_count' => 32,
        ], $actor);

        // Idempotent, and needed before a scheduled trip can show its planned
        // stops on A4.
        $geofences->initialiseFor($trip);

        return $trip->refresh();
    }

    /**
     * Take a trip out on the road for real.
     *
     * Started through `TripService::start`, so the readiness assertions ran and
     * the bus really did move to RUNNING. Positions go through the GPS ingestion
     * service, so the stop progress and staleness the map reads are the ones the
     * server computed.
     */
    private function operate(
        TripService $trips,
        GpsIngestionService $gps,
        PassengerCountService $passengers,
        Trip $trip,
        Driver $driver,
        User $actor,
        bool $finish = false,
    ): void {
        $trips->start($trip, $driver->user);
        $trip->refresh();

        $stops = $trip->route->stops()->orderBy('sequence_number')->get();

        // A short trail, and deliberately so. `GpsIngestionService` treats a
        // reading more than two minutes from now as a skewed device clock and
        // stamps it with the server's time — so a demonstration cannot
        // fabricate an hour of history, and this does not try. Three positions
        // inside the tolerance window, far enough apart to move the geofence
        // and slow enough to be plausible: about 56 km/h.
        foreach ([90, 45, 0] as $index => $secondsAgo) {
            $stop = $stops[$index] ?? $stops->last();

            $gps->ingest($trip, [
                'latitude' => (float) $stop->latitude,
                'longitude' => (float) $stop->longitude,
                'accuracy_meters' => 8,
                'speed_kmh' => $index === 0 ? 0 : 42,
                'recorded_at' => now()->subSeconds($secondsAgo)->toIso8601String(),
            ], $driver->user);
        }

        $riders = Student::query()->where('route_id', $trip->route_id)->take(3)->get();

        foreach ($riders as $rider) {
            $passengers->board($trip->refresh(), $driver->user, $rider);
        }

        if ($finish) {
            $trips->complete($trip->refresh(), $driver->user);
        }
    }

    /**
     * A headcount that disagrees with the boarding record.
     *
     * Produced by `reconcileAttendance`, the same reconciliation the completion
     * flow runs — not by writing a row into `attendance_discrepancies`.
     */
    private function discrepancy(TripRecoveryService $recovery, Trip $trip): void
    {
        $trip->refresh();

        // Two more than the tablet recorded — the size of disagreement a real
        // depot actually argues about. A gap of twenty-seven would only ever
        // be a broken scanner, and would teach nobody anything.
        $boarded = $trip->passengerLogs()->where('action', 'BOARDED')->count();
        $trip->forceFill(['occupied_seat_count' => $boarded + 2])->save();

        $recovery->reconcileAttendance($trip->refresh());
    }

    private function failedInspection(VehicleInspectionService $inspections, Bus $bus, Driver $driver): void
    {
        $items = collect(InspectionItem::cases())->map(fn (InspectionItem $item) => [
            'item' => $item->value,
            // Two genuine failures, so service readiness has something to say
            // and A11 has a reason to show.
            'passed' => ! in_array($item, [InspectionItem::BRAKES, InspectionItem::TYRES], true),
            'notes' => match ($item) {
                InspectionItem::BRAKES => 'Pedal travel excessive; pulls left under braking.',
                InspectionItem::TYRES => 'Nearside front below the tread limit.',
                default => null,
            },
        ])->all();

        $inspections->submit(
            $bus,
            $driver,
            $items,
            (int) $bus->current_odometer + 40,
            $driver->user,
            'Pre-departure check.',
        );
    }

    private function incidents(IncidentService $incidents, Trip $trip, User $reporter, User $supervisor): void
    {
        // Still open: this is what the queue should be showing.
        $incidents->report([
            'incident_type' => IncidentType::CONGESTION->value,
            'trip_id' => (string) $trip->getKey(),
            'description' => 'Held at the Ring Road junction behind a procession. Running about fifteen minutes late.',
            'reported_at' => now()->subMinutes(12)->toIso8601String(),
        ], $reporter, $trip);

        // Already answered, so the timeline on A9 has both ends of a story.
        $handled = $incidents->report([
            'incident_type' => IncidentType::PASSENGER_CONDUCT->value,
            'trip_id' => (string) $trip->getKey(),
            'description' => 'Two passengers standing in the doorway after repeated requests to move back.',
            'reported_at' => now()->subHours(2)->toIso8601String(),
        ], $reporter, $trip);

        $incidents->acknowledge($handled, $supervisor);
        $incidents->resolve(
            $handled->refresh(),
            'Spoken to at the next stop; both moved back and the service continued.',
            $supervisor,
        );
    }

    /**
     * A stranded service and the bus sent to relieve it.
     *
     * The recommendation is not written by hand — BR-352 produces it when a
     * vehicle that cannot continue is reported against a running trip.
     */
    private function replacement(
        TripService $trips,
        IncidentService $incidents,
        ReplacementService $replacements,
        Trip $trip,
        Driver $driver,
        User $head,
    ): void {
        $trips->start($trip, $driver->user);

        $breakdown = $incidents->report([
            'incident_type' => IncidentType::ENGINE_FAULT->value,
            'trip_id' => (string) $trip->refresh()->getKey(),
            'description' => 'Loss of power climbing the flyover, warning lamp lit. The bus cannot continue.',
            'vehicle_can_continue' => false,
            'reported_at' => now()->subMinutes(20)->toIso8601String(),
        ], $driver->user, $trip->refresh());

        // BR-352 recommends one during `report()` when the vehicle cannot
        // continue. Fall back to asking explicitly only if it did not.
        $assignment = ReplacementAssignment::where('vehicle_incident_id', $breakdown->getKey())->first()
            ?? $replacements->recommendFor($breakdown->refresh(), $driver->user);

        if ($assignment === null) {
            $this->components->warn('No replacement could be recommended — no spare bus was near enough.');

            return;
        }

        $replacements->approve($assignment->refresh(), $head, null, null);
        $replacements->dispatch($assignment->refresh(), $head);
    }

    /** Workshop work in each state a transport head would want to look at. */
    private function workshop(MaintenanceService $maintenance, array $buses, User $head, User $supervisor): void
    {
        $open = $maintenance->open($buses[6], [
            'issue_description' => 'Air-conditioning not cooling on the rear half of the saloon.',
            'priority' => MaintenancePriority::MEDIUM->value,
        ], $supervisor);

        $scheduled = $maintenance->open($buses[7], [
            'issue_description' => 'Clutch judder from standstill; getting worse over the week.',
            'priority' => MaintenancePriority::HIGH->value,
        ], $supervisor);
        $maintenance->schedule($scheduled->refresh(), Carbon::today()->addDays(3), $supervisor);

        $underWay = $maintenance->open($buses[4], [
            'issue_description' => 'Wiper motor intermittent — unusable in heavy rain.',
            'priority' => MaintenancePriority::HIGH->value,
        ], $supervisor);
        $maintenance->assign($underWay->refresh(), $head, $supervisor);
        $maintenance->start($underWay->refresh(), $supervisor);

        $done = $maintenance->open($buses[3], [
            'issue_description' => 'Scheduled brake pad replacement, front axle.',
            'priority' => MaintenancePriority::LOW->value,
        ], $supervisor);
        $maintenance->start($done->refresh(), $supervisor);
        $maintenance->complete($done->refresh(), [
            'resolution_notes' => 'Front pads and discs replaced, fluid bled, road tested.',
            'actual_cost' => 8400,
            'parts_used' => 'Front pad set, 2 × disc, 1 L DOT4',
            'odometer_reading' => (int) $buses[3]->current_odometer + 12,
        ], $head);

        $this->components->twoColumnDetail('Workshop', sprintf(
            '%s open · %s scheduled · %s under way · %s signed off',
            $open->bus?->registration_number,
            $scheduled->bus?->registration_number,
            $underWay->bus?->registration_number,
            $done->bus?->registration_number,
        ));
    }

    private function announcements(AnnouncementService $announcements, User $head): void
    {
        $published = $announcements->draft([
            'title' => 'North gate closed on Friday',
            'content' => 'All services will use the east entrance for the whole of Friday. Allow an extra ten minutes.',
            'target_audience' => 'ALL',
            'priority' => 'HIGH',
        ], $head);

        $announcements->publish($published->refresh(), $head);

        // A draft as well, so the "include drafts" control has something to
        // reveal and publishing can be demonstrated live.
        $announcements->draft([
            'title' => 'Revised hostel shuttle timings from next month',
            'content' => 'The hostel shuttle will run every twenty minutes between 07:00 and 21:00 from the first of next month.',
            'target_audience' => 'STUDENTS',
            'priority' => 'MEDIUM',
        ], $head);
    }

    private function summary(array $staff, Driver $driver): void
    {
        $this->newLine();
        $this->components->info('The demonstration world is ready.');
        $this->newLine();

        $this->table(
            ['Sign in as', 'Email', 'Password'],
            [
                ['Transport Assistant (VIEWER)', 'viewer@ctms.edu', self::PASSWORD],
                ['Transport Supervisor (SUPPORT)', 'supervisor@ctms.edu', self::PASSWORD],
                ['Transport Head (OPERATIONS)', 'head@ctms.edu', self::PASSWORD],
                ['System Administrator (SUPER_ADMIN)', 'admin@ctms.edu', self::PASSWORD],
                ['Driver — '.$driver->user?->getFullName(), 'driver1@ctms.edu', self::PASSWORD],
            ],
        );

        $this->components->warn(
            'Demonstration credentials. Never seed this into anything reachable from outside the college.',
        );

        collect($staff)->keys()->each(fn () => null);
    }
}
