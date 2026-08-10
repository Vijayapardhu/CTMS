# Screen specifications

Sixteen root screens, five drawers, twelve dialogs. Every screen states its
access levels, its endpoints, and every state it can be in.

`R` = read for all four levels unless stated. Actions name their required level.

---

# A1 Dashboard

**Route** `/` · **Access** all levels · **Entry** sign-in, sidebar, logo

**Purpose.** Answer "is today normal?" in about five seconds, without clicking.

**Data — six parallel requests, each its own tile state**

| Tile | Endpoint | Derivation |
|---|---|---|
| Trips today | `GET /trips?date=today&per_page=1` | `pagination.total` |
| Running now | `GET /trips?date=today&status=RUNNING` | `pagination.total`, rows reused by the map |
| Fleet | `GET /buses?per_page=100` | Count by `status` client-side |
| Open incidents | `GET /incidents?status=REPORTED&per_page=5` | `total` + the five rows |
| In workshop | `GET /maintenance-tickets?status=OPEN&per_page=1` | `pagination.total` |
| Expiring documents | `GET /fleet/documents/expiring` | Row count |

Readiness is fetched only for buses the fleet response reports as not
`AVAILABLE`, capped at eight (M-INSP).

**Loading strategy.** The page paints its full skeleton at final dimensions in
one frame. Tiles resolve independently into reserved space — **no tile may
change the layout when it lands**. A failed tile shows a retry inside its own
card. The page is `Unavailable` only if all six fail.

**Sections, in order**

1. Greeting + date
2. Five metric cards (C5)
3. Attention Required — ordered by consequence: SOS → breakdown → tracking lost
   → failed inspection → maintenance due → expiring document. Empty renders
   "Nothing needs attention", never a blank panel
4. Live snapshot — small map, running buses, link to A2
5. Today's operations — first ten trips, link to A3

**States** Loading · Loaded · PartialFailure · Empty (no trips today, which is a
sentence not an error) · Offline · Unavailable

**Refresh** 60 s, paused when hidden. No animated counters.

---

# A2 Live Operations

**Route** `/live` · **Access** all levels

**Purpose.** Where every bus is, right now, and whether that is believable.

**Data and polling — the bounded strategy from G2-1**

```text
every 30s   GET /trips?status=RUNNING&date=today
every 30s   GET /trips/{id}/live          for tracked trips only, max 12
on select   GET /trips/{id}/eta?stop_id=<next pending stop>
once/route  GET /routes/{id}/stops        cached for the session
```

Tracked set: at most 12, ordered by (incident on trip, then departure). The
header shows **"Tracking 12 of 14"** whenever the cap bites. Polling pauses on
tab hide, doubles its interval on 429, and stops after three consecutive
failures (Offline).

**Layout** map 60% / list 40% at ≥ 1440. Below 1280 the map is full width and
the list becomes a bottom sheet.

**Markers** C13 by state; stale is the server's `is_stale` and nothing else.

**Selection** opens **D1 Live Trip drawer**: bus, driver, route, current stop,
next stop, road distance (`distance_metres`, `~` when
`distance_is_estimate`), ETA and basis, GPS age, occupancy, [Open trip →].

**States** Loading · Tracking · TrackingDegraded (429) · Empty ("No trip is
running") · Offline · MapUnavailable (no browser key — the list still works)

**Deliberately absent.** Sound, desktop notifications, auto-centring on new
incidents. An operator watching a wall display should not have the viewport
yanked from under them.

---

# A3 Trips

**Route** `/trips` · **Access** all levels

**Data** `GET /trips` with `date` (default today), `status`, `route_id`,
`driver_id`, `bus_id`, `per_page`. Filter option lists from `GET /routes`,
`/drivers`, `/buses`. Every filter is in the URL.

**Columns** Trip · Route · Date · Departure · Bus · Driver · Status · Passengers
· actions. Date and Passengers drop below 1280.

**Row click** → A4. **Actions** Cancel (OPERATIONS, confirm), Reassign
(OPERATIONS), Open.

**States** Loading · Loaded · Empty ("No trips match these filters" with Clear
all) · Error · Refreshing · Offline (mutations disabled)

---

# A4 Trip Details

**Route** `/trips/{id}` · **Access** all levels

**Data** `GET /trips/{id}` · `/live` · `/eta?stop_id=` · `/stops/{id}/manifest`
(on stop selection) · `/corrections` · `GET /incidents?bus_id=`

**Sections** Overview · Live (map + position) · Stops (C17, `StopProgressState`
per stop) · Manifest for the selected stop · Incidents · Corrections

**Actions**

| Action | Endpoint | Level | Confirm |
|---|---|---|---|
| Cancel trip | `POST /trips/{id}/cancel` | OPERATIONS | Yes — names the route and that students will be told |
| Reassign | `POST /trips/{id}/reassign` | OPERATIONS | Yes |
| Add correction | `POST /trips/{id}/corrections` | ⚠ G3-1 | Yes |

**States** Loading · Loaded · PartialFailure (live failed, trip is real) ·
NotFound · Forbidden · Offline

---

# A5 Fleet

**Route** `/buses` · **Access** all levels

**Data** `GET /buses?status=&search=` · `GET /fleet/documents/expiring` for the
document badge. Readiness is **not** fetched per row — it is one call per bus
and a fleet list would be N+1. The Ready column shows readiness only for buses
already reported as not `AVAILABLE`, capped at eight.

**Columns** Registration (mono) · Model · Capacity · Status · Driver · Current
trip · Readiness · Documents · actions

**Row click** → A6.

---

# A6 Bus Details

**Route** `/buses/{id}` · **Access** all levels

**Data** `GET /buses/{id}` · `/service-readiness` · `/inspections` ·
`/documents` · `GET /maintenance-tickets?bus_id=` ·
`GET /incidents?bus_id=` · `GET /trips?bus_id=&date=today`

**Tabs** Overview · Readiness · Inspections · Maintenance · Documents ·
Incidents

Readiness renders `cleared` plus **`reasons[]` verbatim** as a list. These are
the same sentences the driver reads on the bus; rewording them here would give
two people two different accounts of the same vehicle.

**Actions** Change status (`PATCH /buses/{id}/status`, OPERATIONS, confirm —
this is the return-to-service step of J10) · document CRUD (OPERATIONS)

---

# A7 Drivers

**Route** `/drivers` · **Access** all levels

**Data** `GET /drivers` · `GET /drivers/{id}` · `GET /trips?driver_id=&date=today`

**Columns** Name · Status · Licence · Expiry · Assigned bus · Today's trip

Licence data comes from the driver record (G0-1). Expiry within 30 days is
`caution`; expired is `critical`.

**Actions** Change status (`PATCH /drivers/{id}/status`) · assign/unassign bus
(OPERATIONS)

---

# A8 Incidents

**Route** `/incidents` · **Access** all levels

**Data** `GET /incidents?status=&type=&bus_id=`. Default: the open queue
(`REPORTED` and `ACKNOWLEDGED`), sorted client-side by severity then time.

**The screen says it is showing the open queue only.** There is no severity or
date filter on the backend (G1-2), so offering one would silently under-report
across pages.

**Columns** Severity · Type · Bus · Driver · Trip · Time · Status · actions

**States** Loading · Loaded · Empty ("No open incidents" — the good day) ·
Error · Offline

---

# A9 Incident Details

**Route** `/incidents/{id}` · **Access** all levels

**Data** `GET /incidents/{id}` · `GET /evidence/{id}` per cited photograph ·
`GET /replacements`

**Sections** Header (severity, type, status, time) · the driver's own words ·
bus/driver/trip/location + small map · Evidence (C25) · Timeline (C17) ·
Replacement, when one exists

**Actions**

| Action | Level | Confirm |
|---|---|---|
| Acknowledge | SUPPORT | No — it means "seen", and a dialog buys nothing in an emergency |
| Resolve | SUPPORT | Yes |
| Close | OPERATIONS | Yes |
| Add note | ⚠ | No |
| Cancel incident | ⚠ | Yes — it withdraws an alert others may have acted on |
| Approve / reject replacement | OPERATIONS | Yes |
| Dispatch / mark arrived | SUPPORT | No |

Every refusal renders the backend's 409 message verbatim.

---

# A10 Maintenance

**Route** `/maintenance` · **Access** all levels

**Data** `GET /maintenance-tickets?status=&priority=&bus_id=` ·
`GET /maintenance-tickets/{id}` · `GET /preventive-maintenance`

**Columns** Ticket · Bus · Issue · Priority · Status · Assigned · Opened ·
Odometer

**Actions** Open (SUPPORT) · Assign · Schedule · Start (SUPPORT) · Complete
(OPERATIONS, confirm) · Cancel (OPERATIONS, confirm)

**Return to service** is presented after Complete as a distinct guided step
that calls `PATCH /buses/{id}/status`. The confirmation names the bus. Two
calls, one intent, honestly labelled — there is no single endpoint (J10).

A second tab lists preventive maintenance due.

---

# A11 Inspections

**Route** `/inspections` · **Access** all levels

**Scope — read this before implementing.** There is no fleet-wide inspection
endpoint (G2-2). This screen is **today's failures**, not a history:

```text
GET /buses                         → the fleet
  for each bus not AVAILABLE, max 8:
GET /buses/{id}/service-readiness  → cleared + reasons
GET /buses/{id}/inspections        → on drill-down only
GET /inspections/{id}              → failed items and evidence
```

The header states the scope. Full history lives on A6, where the backend
supports it.

**Actions** Open a maintenance ticket from a failure, pre-filled with bus,
failed item and inspection reference (SUPPORT).

---

# A12 Students

**Route** `/students` · **Access** all levels

**Data** `GET /students` · `GET /students/{id}` on selection only

**Columns** Name · Roll · Route · Stop · Pass · Status

**Personal data.** The panel fetches student detail only when a row is opened —
never eagerly to fill a list — because each read is recorded in the backend's
data-access log and bulk-reading a class of students to render a table nobody
asked for is exactly what that log exists to catch.

**Actions** Assign / clear transport (OPERATIONS) · change status (OPERATIONS)

---

# A13 Alerts

**Route** `/alerts` · **Access** all levels

**Data** `GET /notifications` · `/unread-count` · `GET /notification-log` ·
`/notification-log/health`

**Two panels, deliberately separate (G1-4).** "My alerts" is this admin's own
notification inbox. "Delivery health" is whether the system is reaching
handsets at all, from the log and health endpoints.

**Actions** Mark read / all read · Resend a failed delivery (⚠ G3-1)

**States** Loading · Loaded · Empty ("Nothing from the system") · **Error with
retry — never dressed as empty** · Offline

---

# A14 Announcements

**Route** `/announcements` · **Access** all levels read

**Data** `GET /announcements` · `GET /announcements/{id}`

**Actions** Create / edit draft (OPERATIONS) · Publish (OPERATIONS, confirm
naming the audience) · Withdraw (OPERATIONS, confirm)

`AnnouncementAudience` is `ALL STUDENTS DRIVERS ADMINS`. The publish dialog
names the audience in words, because "publish" and "tell every student in the
college" should not feel like the same click.

---

# A15 Reports

**Route** `/reports/{kind}` · **Access** all levels

**Data** one of `GET /reports/trips`, `/reports/attendance`, `/reports/fleet`,
`/reports/incidents`, `/reports/maintenance`, `/reports/occupancy`, each with
`date`, `from`, `to`. Range defaults to this month and lives in the URL.

**Download** builds CSV in the browser from the loaded rows. Labelled
**"Download this table"**, not "Export" — there is no server-side export and
implying an authoritative extract would be a lie (G1-3).

**States** Idle · Running · Loaded · Empty ("No activity in this range") ·
Error · Offline

---

# A16 Audit — SUPER_ADMIN only

**Route** `/admin/audit` · **Access** SUPER_ADMIN

**Two tabs, never merged.** Audit log is about change; data access log is about
reading. Merging them makes both unusable.

**Data** `GET /audit-logs?action=&date=&from=&to=` · `GET /audit-logs/{id}` ·
`GET /data-access-logs` · `GET /retention-runs`

**Columns** When · Actor · Action · Resource · Record · IP; expanding shows the
before/after diff (C26).

**Action** Subject access export (`POST /users/{id}/subject-access-export`,
confirm naming the person whose data is leaving the system).

**Forbidden state.** Below SUPER_ADMIN the sidebar entry is absent and a direct
URL renders "You don't have permission to view this." — with no hint of what is
behind it.

---

# A17 Routes — read-only, MVP-if-cheap

`GET /routes`, `/routes/{id}`, `/routes/{id}/stops`. Reference for filters and
the map. Every write is `OPERATIONS` network planning and is post-MVP.

# A18 Accounts — SUPER_ADMIN only

`GET /users`, `/users/{id}`, `POST /users`, `PATCH /users/{id}/status`.
Deactivation is confirmed and names the person; a deactivated user is rejected
on every request, not just at next login.

---

# Drawers and dialogs

| ID | Drawer | Opened from |
|---|---|---|
| D1 | Live trip | A2 marker or list row |
| D2 | Bus detail | A5 row |
| D3 | Driver detail | A7 row |
| D4 | Student detail | A12 row |
| D5 | Maintenance ticket | A10 row |

| ID | Dialog | Destructive |
|---|---|---|
| M1 | Cancel trip | Yes |
| M2 | Reassign trip | No |
| M3 | Resolve incident | No |
| M4 | Close incident | Yes |
| M5 | Cancel incident | Yes |
| M6 | Approve / reject replacement | No |
| M7 | Complete maintenance | No |
| M8 | Return bus to service | Yes |
| M9 | Publish announcement | Yes — names the audience |
| M10 | Withdraw announcement | Yes |
| M11 | Deactivate account | Yes |
| M12 | Subject access export | Yes — names the person |

---

# States every screen must define

| State | Rule |
|---|---|
| Loading | Skeleton at final dimensions. Never a bare spinner |
| Empty | Calm. Never red, never "error", never a warning icon |
| Error | What failed + retry. 403 is a fixed sentence; 409 is verbatim; 5xx is generic |
| Forbidden | "You don't have permission to perform this action." No path, no internals |
| Offline | Reads keep their data, marked stale. **Every mutation control disabled** |
| Refreshing | Existing data stays. A progress line, never a skeleton |
| PartialFailure | The parts that loaded are real and stay. The part that failed retries in place |
