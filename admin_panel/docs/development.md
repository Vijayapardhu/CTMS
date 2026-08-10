# Running the admin panel against a real backend

Every slice from 2 onwards is verified against the real API, not only against
MSW. This is how to stand that environment up from nothing, repeatably.

No credentials here are secret: they are the development seeder's, they exist
only in a local SQLite file, and that file is gitignored.

## 1. The backend, on SQLite

Postgres and Redis are not required for development. The suite already runs on
SQLite, and so can the server.

```bash
cd backend

# A throwaway development database. Delete it whenever you want a clean start.
rm -f database/dev.sqlite && touch database/dev.sqlite

DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/dev.sqlite" \
CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
php artisan migrate --force --seed
```

That gives 5 routes, 10 buses, 10 drivers, students, and one administrator.

## 2. Trips for today

The seeders create the network, not a day's operations, and the panel asks for
**today's** trips. Without this step every screen is correctly but unhelpfully
empty.

```bash
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/dev.sqlite" \
CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
php artisan tinker --execute='
$routes = App\Models\Route::all(); $buses = App\Models\Bus::all(); $drivers = App\Models\Driver::all();
foreach ([["RUNNING",4],["SCHEDULED",9],["COMPLETED",4],["CANCELLED",1]] as [$status,$n]) {
  for ($i=0; $i<$n; $i++) {
    App\Models\Trip::factory()->create([
      "route_id"=>$routes->random()->id, "bus_id"=>$buses->random()->id,
      "driver_id"=>$drivers->random()->id, "trip_date"=>now()->toDateString(), "status"=>$status,
    ]);
  }
}
'
```

**Stop progress is created at trip start**, by `GeofenceService::initialiseFor()`
— see `TripService`. A factory-made trip has never started, so it has no stops
and `/live` correctly returns an empty list. To get a trip with a stop history,
call the same service the start endpoint calls:

```bash
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/dev.sqlite" \
CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
php artisan tinker --execute='
$g = app(App\Services\Tracking\GeofenceService::class);
foreach (App\Models\Trip::whereIn("status", ["RUNNING","COMPLETED"])->whereDate("trip_date", now())->get() as $trip) {
  $g->initialiseFor($trip->load("route"));
}
'
```

For a running trip that looks alive, stamp a recent position — `is_stale` is
computed from `last_gps_update` server-side:

```bash
php artisan tinker --execute='
$t = App\Models\Trip::where("status","RUNNING")->whereDate("trip_date", now())->first();
$t->forceFill(["current_latitude"=>16.97,"current_longitude"=>82.09,"last_gps_update"=>now(),"occupied_seat_count"=>18])->save();
'
```

## 3. Serve the API

```bash
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/dev.sqlite" \
CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
php artisan serve --host=127.0.0.1 --port=8000
```

## 4. Serve the panel

```bash
cd admin_panel
npm install
npm run dev            # http://localhost:5174
```

**The proxy matters.** The backend has no CORS layer — the driver app is a
native client and never needed one — so a browser on another origin cannot call
it. Vite proxies `/api` to `http://127.0.0.1:8000` in both `dev` and `preview`,
and the panel's API base is relative, so the browser only ever talks to its own
origin. Override the target with `CTMS_BACKEND` if the API is elsewhere:

```bash
CTMS_BACKEND=http://127.0.0.1:9000 npm run dev
```

See `docs/admin-panel/00-backend-gaps.md` G2-3 — deploying the panel on a
separate origin needs a decision, and this proxy is not it.

## 5. Sign in

The development administrator, from `AdminSeeder` — `SUPER_ADMIN`:

```text
admin@ctms.edu / Admin@123
```

To exercise the other tiers, change one account's level:

```bash
php artisan tinker --execute='
App\Models\Admin::first()->forceFill(["access_level"=>"VIEWER"])->save();
'
```

`VIEWER` · `SUPPORT` · `OPERATIONS` · `SUPER_ADMIN`. The panel reads the level
from `profile.access_level` on `/auth/me`; the server enforces it regardless.

## 6. Checks

```bash
cd admin_panel
npm run typecheck
npm test
npm run build

cd ../backend
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan --memory-limit=1G
```

## What `/live` actually does, per trip status

Verified against this environment, because A4's design depends on it:

| Status | HTTP | `position` | `stops` |
|---|---|---|---|
| `SCHEDULED` | 200 | `null` | empty — the trip has never started, so there is no history |
| `RUNNING` | 200 | present, with server-computed `is_stale` | full, with live states |
| `COMPLETED` | 200 | `null` | full, with final states and `arrived_at` |

So the endpoint is **not** running-only. A completed trip keeps its stop
history. A scheduled trip has none, and that is correct rather than a gap — its
*planned* stops come from `GET /routes/{id}/stops`.
