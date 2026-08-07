<?php

use App\Jobs\CloseOverdueTrips;
use App\Jobs\DetectStalledTrips;
use App\Jobs\EscalateUnacknowledgedIncidents;
use App\Jobs\GenerateDailyTrips;
use App\Jobs\ProposeConsolidations;
use App\Jobs\PurgeExpiredData;
use App\Jobs\ReconcileAttendance;
use App\Jobs\ScanExpiringDocuments;
use App\Jobs\ScanPreventiveMaintenance;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled background processes
|--------------------------------------------------------------------------
|
| Each corresponds to a numbered background process in
| docs/blueprint/08-functionality.md §3. Every one is idempotent, so a
| duplicate run is harmless, and every one logs its outcome.
|
*/

// BG-14 — vehicle document expiry scan (N-22, N-23).
// Runs early enough that a lapse discovered today can still be acted on
// before the morning run.
Schedule::job(new ScanExpiringDocuments)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer();

// BG-01 — generate tomorrow's trips from the timetable.
// Late enough that the day's roster changes are in, early enough that the
// exception list is waiting when operations arrive.
Schedule::job(new GenerateDailyTrips)
    ->dailyAt('22:00')
    ->withoutOverlapping()
    ->onOneServer();

// BG-09 — close trips a driver left running past the arrival buffer.
// Frequent, because a bus showing as on the road when it is parked distorts
// every live view until it is corrected.
Schedule::job(new CloseOverdueTrips)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// BR-356 — escalate incidents nobody has acknowledged. Every minute, because
// a life-safety incident tolerates two minutes of silence and no more.
Schedule::job(new EscalateUnacknowledgedIncidents)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// BG-08, BR-259 — a running trip that has stopped reporting its position.
Schedule::job(new DetectStalledTrips)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// BG-16, BR-366 — open tickets for services that have fallen due. Before the
// morning departures, so a service due today is visible while there is still
// time to swap the vehicle.
Schedule::job(new ScanPreventiveMaintenance)
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer();

// BG-11, FR-13 — propose merges for half-empty trips, and expire the
// proposals nobody decided. Hourly during service, because occupancy that
// justified a merge an hour ago may not justify it now.
Schedule::job(new ProposeConsolidations)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// BG-19, BR-307, BR-504 — purge data past its retention window. Overnight,
// and only ever the fine-grained location trace: attendance and trip history
// survive, because losing them would destroy the answer to "was my child on
// that bus" (BR-505).
Schedule::job(new PurgeExpiredData)
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// BG-20, BR-266 — compare headcounts to boarding events once the day's trips
// have closed. Late enough that the last run is in.
Schedule::job(new ReconcileAttendance)
    ->dailyAt('23:30')
    ->withoutOverlapping()
    ->onOneServer();
