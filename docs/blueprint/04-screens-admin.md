# Phase 2 — Admin & Staff Console Screens

111 screens. Web. Used by Super Administrator, Transport Manager, Operations Controller,
Support Desk, Maintenance Coordinator, Finance Officer and Auditor.

Conventions from [02-screens-conventions.md](02-screens-conventions.md) apply throughout.
Where **Access** names a role, all higher-privilege roles are implied unless stated otherwise.

---

## A. Dashboard & Live Operations (11 screens)

### AD-01 · Operations Dashboard `P0`

**Purpose** — The single screen that answers "is today going well?" in five seconds.
**Access** — All staff. Content varies by role: finance sees revenue tiles, maintenance sees
the workshop queue, operations sees the live fleet.
**Entry** — Sign-in landing; the home link from anywhere.
**Exit** — Every module, by drilling into any tile.

**Actions**
- View live tiles: buses running now, trips in progress, trips completed today, trips delayed,
  active incidents, unacknowledged alerts, occupancy against capacity, drivers on duty
- View the exception list — the most important element on the screen: unstaffed schedules,
  buses with expiring documents, drivers with expiring licences, stalled trips, trips left
  running, capacity breaches, failed notifications
- Acknowledge an alert inline without leaving the dashboard
- Jump to the live map, to any listed exception, or to any tile's underlying list
- Choose the date (defaults to today; past dates render the same layout historically)
- Rearrange and hide tiles; the layout is saved per user
- Refresh manually; auto-refresh on an interval

**Validations** — Date cannot exceed the next generation horizon.

**States**
- *Empty — outside service hours*: shows the next scheduled run rather than a bare "nothing
  running", which reads as a fault
- *Empty — non-operating day*: names the reason from the service calendar ("Republic Day —
  no service")
- *Degraded*: a persistent banner when a dependency (maps, push, SMS) is failing, with the
  functional consequence spelled out

**Workflows** — [F-05 Daily Operations](09-system-flows.md#f-05).

---

### AD-02 · Live Fleet Map `P0` `FR-07`

**Purpose** — Every moving bus, in real time, on one map.
**Access** — Operations, Manager, Support (view only), Super Admin.
**Entry** — Dashboard; sidebar; an alert; a trip detail.
**Exit** — Trip detail `AD-31` · Bus detail `AD-12` · Driver detail `AD-22` · Incident `AD-42`.

**Actions**
- View all running buses with heading, speed, occupancy and delay
- Filter by route, by delay threshold, by occupancy band, by status
- Select a bus to open a side panel: trip, driver, route progress, next stop, ETA, occupancy,
  last update age
- Overlay route lines, stops and geofences
- Follow a single bus; return to fleet view
- Replay the last N hours of a bus's movement
- Message the driver; call the driver; broadcast to a route's passengers
- Open the trip, the bus record, or the driver record
- Toggle clustering, satellite view, and traffic overlay

**Validations** — None (read surface). Position age over the stale threshold is rendered
distinctly rather than shown as current truth.

**States**
- *Empty*: "No buses running" with the next departure time
- *Stale position*: the marker changes appearance and shows the age; it must be impossible to
  mistake a twenty-minute-old position for a live one
- *Provider failure*: the map falls back to a schematic route-and-stop view listing positions
  as "between stop 4 and 5"; tracking data is still shown, just not on a tiled map
- *Loading*: the map frame renders first, markers stream in

**Workflows** — [F-07 Trip Execution](09-system-flows.md#f-07), [F-11 Emergency](09-system-flows.md#f-11).

---

### AD-03 · Active Trips `P0` `FR-06`

**Purpose** — Tabular counterpart to the map, for scanning and acting rather than watching.
**Access** — Operations, Manager, Support (view only).
**Actions** — View every trip in progress with route, bus, driver, departure, progress, next
stop, ETA, occupancy, delay; sort by delay or occupancy; open detail; force-end a trip;
reassign driver or bus; cancel; message the driver; bulk-notify a route.
**Validations** — Force-end requires a reason. Reassignment re-checks availability, licence
validity and duty hours at the moment of the change, not at page load.
**List behaviour** — Live-updating, no pagination, filter by route / status / delay band.
**States** — Rows for stalled trips are visually distinct and sort to the top.

---

### AD-04 · Today's Schedule `P0` `FR-05`

**Purpose** — What is supposed to happen today, versus what is happening.
**Access** — All staff.
**Actions** — Timeline view of all scheduled trips with planned versus actual; identify
not-yet-started, running, completed, cancelled; start a trip on a driver's behalf; assign a
substitute; cancel; add an unscheduled trip.
**Validations** — Manual start requires bus and driver availability. Adding an ad-hoc trip
runs the same conflict checks as schedule creation.
**States** — *Behind schedule* rows are highlighted; the count of at-risk trips is shown above
the list.

---

### AD-05 · Alert Centre `P0`

**Purpose** — Everything demanding a human decision, in one queue.
**Access** — Operations, Manager, Maintenance (own categories), Super Admin.
**Entry** — Dashboard badge; the global alert bell; push notification.
**Exit** — The subject of the alert.

**Actions** — View alerts by severity; acknowledge (records who and when); assign to a
colleague; resolve with a note; snooze with a required reason and duration; escalate; filter
by type, severity, status and assignee; bulk-acknowledge.

**Validations** — Critical alerts cannot be snoozed. Resolution requires a note. Acknowledging
does not resolve — the two are distinct and both are tracked.

**States** — *Empty*: "All clear" with the time of the last resolved alert.
**Workflows** — [F-11 Emergency](09-system-flows.md#f-11).

---

### AD-06 · Alert Detail `P1`
**Purpose** — Full context for one alert. **Access** — As AD-05.
**Actions** — Read the full payload and timeline; see related entities; act directly (dispatch
replacement, open ticket, notify passengers); add notes; view the audit of who did what.

### AD-07 · Trip Monitor (single trip live) `P1` `FR-07`
**Purpose** — Deep watch of one trip. **Access** — Operations, Manager, Support (view).
**Actions** — Live map focused on this trip; stop-by-stop progress with planned/actual;
boarding events as they happen; occupancy over time; delay trend; message driver; intervene.
**States** — Stops are marked pending / arrived / skipped; a skipped stop is prominent.

### AD-08 · Route Live View `P2` `FR-07`
**Purpose** — All trips on one route at once, for a route running multiple buses.
**Actions** — Compare progress and occupancy across buses; spot bunching; propose consolidation.

### AD-09 · Service Status Board `P2` `[NEW]`
**Purpose** — A read-only wall display for the transport office.
**Access** — Any staff; a kiosk mode requires no interaction.
**Actions** — Auto-rotating summary: running buses, delays, incidents. Large type, no controls.

### AD-10 · Broadcast Composer `P1` `FR-10`
**Purpose** — Send an urgent message to a targeted audience during operations.
**Access** — Operations, Manager.
**Actions** — Choose audience (route, trip, stop, whole service, role); compose; select
channels; preview the recipient count; send now or schedule; require acknowledgement.
**Validations** — Audience must be non-empty; recipient count is shown and confirmed before
send; a send above a threshold requires a second confirmation.
**States** — *Success* reports actual delivery counts per channel, not merely "sent".

### AD-11 · Operations Handover Log `P2` `[NEW]`
**Purpose** — Shift-to-shift continuity for controllers.
**Actions** — Write a handover note; read the previous shift's; flag open items; acknowledge
receipt of handover.

---

## B. Fleet — Buses (13 screens)

### AD-12 · Bus List `P0` `FR-02`

**Purpose** — The fleet register.
**Access** — All staff (read); Manager and Maintenance (write).
**Entry** — Sidebar; dashboard tile; any bus reference.
**Exit** — Bus detail · Add bus · Import.

**Actions** — View registration, name, model, capacity, status, current route, assigned
driver, next service due, document status; filter by status, capacity band, fuel type,
document validity, assignment; search; sort; open detail; add; import; export; bulk status
change; bulk export.

**Validations** — Bulk status change validates each transition individually and reports
per-item outcomes rather than failing the whole batch.

**List behaviour** — Standard. Default sort by registration. Buses with an expired statutory
document are flagged in the list itself, not only on detail.

**States** — *Empty*: explains the fleet register and offers add or import.

---

### AD-13 · Bus Detail `P0` `FR-02`

**Purpose** — Everything about one vehicle.
**Access** — All staff (read); Manager and Maintenance (write).
**Exit** — Edit · Maintenance ticket · Trip detail · Driver detail · Documents.

**Actions** — View identity, specification, capacity, status with its history, current
assignment, live position when running; tabs for Trips, Maintenance, Incidents, Documents,
Fuel, Occupancy history, Audit; change status; assign or release a driver; retire; print a
vehicle summary; view on the live map.

**Validations** — Status changes obey the state machine and are refused with a business-language
reason. Retiring is refused while an unfinished trip exists.

**States** — *Retired*: the record is read-only with a banner and a restore action for
authorised roles.

---

### AD-14 · Add Bus `P0` `FR-02`
**Actions** — Enter registration, name, model, year, capacity, fuel type, colour, GPS device
identifier, initial odometer, purchase details, insurance and fitness expiry; save; save-and-add-another.
**Validations** — Registration unique (case-insensitive) and format-checked; capacity 1–120;
year within a plausible range; expiry dates in the future; GPS device identifier unique.
**States** — A new bus always enters the fleet as `AVAILABLE`, regardless of what the payload asks for.

### AD-15 · Edit Bus `P1` `FR-02`
**Actions** — Amend specification and details. **Status is not editable here** — it has its own
screen with its own rules.
**Validations** — Capacity cannot drop below the booked count on any active trip.

### AD-16 · Bus Status Change `P1` `FR-02`
**Purpose** — Move a bus between operational states deliberately.
**Actions** — Choose the target state from the **legal** transitions only; give a reason;
confirm; view the consequences before committing (trips affected, driver released).
**Validations** — Illegal transitions are not offered. Taking a bus out of service with an
active trip is refused and explains which trip.

### AD-17 · Bus Documents `P0` `[NEW]`
**Purpose** — Statutory compliance register per vehicle.
**Access** — Manager, Maintenance, Auditor.
**Actions** — Record and upload fitness certificate, insurance, pollution certificate, permit,
road tax, with issue and expiry dates; view expiry status; replace on renewal; download; view
history.
**Validations** — Expiry after issue; file type and size limits; an expired mandatory document
**blocks the bus from assignment** and is stated as a hard bar.
**States** — Expiring within 30 days is warned; expired is blocking and raises an alert.

### AD-18 · Bus Import `P1` `[NEW]`
**Actions** — Download template; upload CSV/XLSX; map columns; preview with per-row validation;
choose create-only or create-and-update; commit; download the error report.
**Validations** — Every row validated before any row commits; duplicates detected against
registration; the import is atomic per batch with a full outcome report.
**States** — *Partial*: shows exactly which rows failed and why, with a corrected-file re-upload path.

### AD-19 · Fuel Log `P2` `[NEW]`
**Actions** — Record refuelling with odometer, quantity, cost, station, receipt image; view
history; compute consumption; flag anomalies (impossible efficiency, odometer going backwards).
**Validations** — Odometer must be greater than the previous reading; quantity within tank
capacity; date not in the future.

### AD-20 · Bus Occupancy History `P2` `FR-15`
**Actions** — View occupancy per trip over time; identify chronically under- or over-used
vehicles; feed capacity planning; export.

### AD-21 · Bus Assignment Board `P1` `FR-02`
**Purpose** — See the whole fleet's allocation at a glance.
**Actions** — Grid of buses against days and time bands showing route, driver and gaps;
drag to reassign; spot unallocated vehicles and unstaffed schedules.
**Validations** — Every drop re-runs conflict detection before committing.

### AD-22 · Vehicle Inspection Records `P1` `[NEW]`
**Actions** — View every pre-trip inspection submitted by drivers with pass/fail per item and
photographs; filter by outcome; open the resulting ticket for failures.

### AD-23 · Bus Retire / Restore `P1` `FR-02`
**Actions** — Retire with a reason and disposal detail; restore a retired vehicle.
**Validations** — Refused while assigned to an unfinished trip or currently running. Retiring
releases the assigned driver and is stated in the confirmation.

### AD-24 · Fleet Capacity Planner `P2` `[NEW]`
**Actions** — Compare assigned students per route against scheduled seat capacity; highlight
over-subscription and waste; model the effect of adding or removing a bus.

---

## C. Fleet — Drivers (10 screens)

### AD-25 · Driver List `P0` `FR-03`
**Access** — Manager, Operations, Super Admin. **Not** Support or Finance — licence numbers
and violation history are personal data with no support or billing purpose.
**Actions** — View name, licence, class, expiry, status, assigned bus, trips completed,
rating; filter by status, assignability, licence validity, class; search; open detail; add;
import; export; bulk roster actions.
**List behaviour** — Drivers with an expired or expiring licence are flagged in the list.

### AD-26 · Driver Detail `P0` `FR-03`
**Actions** — View identity, contact, licence and compliance, current duty status, assigned
bus, live position when on a trip; tabs for Trips, Attendance/duty hours, Incidents, Leave,
Documents, Performance, Audit; assign or release a bus; change duty status; record leave;
retire.
**Validations** — Bus assignment requires a valid licence, an on-duty status and an operational
bus, each checked at the moment of assignment.

### AD-27 · Add Driver `P1` `FR-03`
**Actions** — Create the account and profile together, or attach a profile to an existing
driver account; enter licence number, class, expiry, contact, emergency contact, employment
details; upload licence image.
**Validations** — Licence number unique; expiry in the future; the target account must hold the
driver role and must not already have a profile.

### AD-28 · Edit Driver `P1` `FR-03`
**Actions** — Amend licence and employment details, record renewals.
**Validations** — Duty status and bus assignment are not editable here. The profile cannot be
re-pointed at a different user account.

### AD-29 · Driver Duty Roster `P1` `[NEW]`
**Purpose** — Who is driving what, this week.
**Actions** — Weekly grid of drivers against days; assign to schedules; spot unstaffed
schedules and overloaded drivers; copy last week; publish the roster (which notifies drivers).
**Validations** — Duty-hour ceilings and rest periods enforced on assignment; conflicts shown
before publishing, not after.

### AD-30 · Driver Leave Management `P1` `[NEW]`
**Actions** — View leave requests; approve or reject with a note; record unplanned absence;
see the coverage impact of an approval before deciding.
**Validations** — Approving leave that leaves a schedule unstaffed warns and requires
acknowledgement; it does not silently create a gap.

### AD-31 · Driver Documents `P1` `[NEW]`
**Actions** — Licence, medical certificate, background check, training records with expiry
tracking; upload; renew; view history.
**Validations** — An expired licence blocks assignment absolutely.

### AD-32 · Driver Performance `P2` `FR-15`
**Actions** — Punctuality, incident count, fuel efficiency, student feedback, harsh-driving
events; compare against fleet averages; export.
**States** — Presented as operational data for coaching, with a stated review period; a single
bad day must not be presented as a performance verdict.

### AD-33 · Driver Import `P2` `[NEW]`
As AD-18, for drivers, with licence-number deduplication.

### AD-34 · Driver Compliance Board `P1` `[NEW]`
**Purpose** — One screen showing every driver's compliance state.
**Actions** — Licence expiry, medical expiry, training currency, duty-hour breaches; filter to
"blocking" only; bulk-notify affected drivers.

---

## D. People — Students, Parents, Staff (14 screens)

### AD-35 · Student List `P0` `FR-04`
**Access** — Manager, Operations, Support (read), Super Admin. Finance sees a separate
billing-scoped list.
**Actions** — View name, registration number, department, year, route, stop, pass status,
record status; filter by status, route, department, year, unassigned, pass validity; search;
open detail; add; import; export; bulk assign transport; bulk status change; bulk notify.
**List behaviour** — "Unassigned" is a first-class filter — it is the working queue at the
start of term.

### AD-36 · Student Detail `P0` `FR-04`
**Actions** — View identity, academic details, contact, guardians, transport assignment, pass
status; tabs for Attendance, Trips, Notifications, Payments, Requests, Audit; assign or clear
transport; change status; issue or renew a pass; link a guardian; view live bus during service.
**Access notes** — Support sees attendance summaries but not continuous location traces.

### AD-37 · Add Student `P1` `FR-04`
**Actions** — Create account and profile; enter registration number, department, year, hostel,
emergency contact; optionally assign transport immediately.
**Validations** — Registration number unique; the account must hold the student role and must
not already have a profile.

### AD-38 · Edit Student `P1` `FR-04`
**Validations** — Transport assignment, pass entitlement and status are not editable here.

### AD-39 · Assign Transport `P0` `FR-04`
**Purpose** — Seat a student on a route.
**Actions** — Choose route (active only), pickup stop, optional drop-off stop; see remaining
capacity on that route before committing; view the stop on a map; effective-from date; save.
**Validations** — Student must be active and hold a valid pass; route must be active; stops
must belong to that route and permit pickup/drop-off respectively; pickup and drop-off must
differ; capacity check with an explicit override path requiring a reason.
**States** — *Blocked — no valid pass*: explains and links to the pass screen rather than
silently failing.

### AD-40 · Bulk Transport Assignment `P1` `[NEW]`
**Purpose** — Seat a cohort at the start of term without doing it one at a time.
**Actions** — Filter a student set; choose route and stop; preview the capacity effect;
commit; download the outcome report.
**Validations** — Per-student rules still apply; the preview shows how many will succeed and
how many will fail, and why, before anything is written.

### AD-41 · Student Import `P1` `[NEW]`
As AD-18, with match-and-merge against existing registration numbers and a human review queue
for ambiguous matches.

### AD-42 · Student Requests Queue `P1` `[NEW]`
**Purpose** — Route change, stop change, pass renewal and correction requests from students
and parents.
**Actions** — Triage; approve with an effective date; reject with a reason; request more
information; bulk-approve a filtered set.
**Validations** — Approval runs the same assignment rules as AD-39. The requester is notified
of every outcome, including rejection with its reason.

### AD-43 · Parent List `P1` `[NEW]`
**Actions** — View guardians, their linked students, verification state, contact details;
filter by verification state; search; open detail; invite; export.

### AD-44 · Parent Detail `P1` `[NEW]`
**Actions** — View the guardian, their linked students and the data classes each link grants;
resend an invitation; revoke a link; view their notification history.

### AD-45 · Guardian Link Verification `P0` `[NEW]`
**Purpose** — Approve or refuse a claimed parent–student relationship.
**Access** — Manager, Support (recommend only), Super Admin.
**Actions** — Review the claim and supporting evidence; approve, choosing which data classes
the link grants; reject with a reason; request documentation.
**Validations** — A link cannot be approved on the strength of the claimant's assertion alone.
Approval is audit-logged with the approver and the evidence reference. This screen is the
single control preventing an unauthorised adult from tracking a child.

### AD-46 · Staff List `P1`
**Access** — Super Admin, Manager (view).
**Actions** — View staff accounts, roles, last activity, MFA state; filter; search; invite;
deactivate; export.

### AD-47 · Staff Detail `P1`
**Actions** — View and change role; view permission set; view activity history; force
password reset; revoke sessions; deactivate.
**Validations** — Cannot change one's own role or deactivate oneself; the two-super-admin
minimum is enforced with a clear message.

### AD-48 · Invite Staff `P1`
**Actions** — Enter email and role; add a note; send; resend; revoke a pending invitation.
**Validations** — Email unique; role assignable by the inviter (a manager cannot mint a super
admin); invitations expire.

---

## E. Network — Routes, Stops, Schedules, Calendar (14 screens)

### AD-49 · Route List `P0` `FR-05`
**Actions** — View code, name, distance, duration, stop count, status, assigned students,
scheduled trips; filter by status; search; open; add; export; bulk status change.

### AD-50 · Route Detail `P0` `FR-05`
**Actions** — View route with its stops on a map; tabs for Stops, Schedules, Students, Trips,
Occupancy, Audit; edit; manage stops; change status; retire; duplicate as a starting point
for a new route.
**Validations** — Retiring is refused while students are assigned or active schedules exist,
and names the blocking count.

### AD-51 · Add Route `P1` `FR-05`
**Actions** — Enter name, code, description, start and end points, distance, duration; save
and continue to stop creation.
**Validations** — Name and code unique; distance and duration positive and plausible.

### AD-52 · Edit Route `P1` `FR-05`
**Validations** — Status and stop count are not editable here. Editing a route with assigned
students warns that they will be notified, and requires an effective date.

### AD-53 · Route Stop Manager `P0` `FR-05`
**Purpose** — Build and reorder the itinerary. The most intricate screen in the network module.
**Actions** — View stops in sequence on a map and as a list; add a stop by dropping a pin or
entering an address; reorder by drag; insert between existing stops; edit; remove; set stop
type (pickup / drop-off / both); set geofence radius; set waiting time and cumulative
distance; preview the drawn route.
**Validations** — Sequence stays contiguous 1..N automatically on insert and removal; a stop
cannot be removed while students are assigned to it, and the blocking count is named;
coordinates must fall inside the configured service area; duplicate coordinates within a
tolerance are warned.
**States** — *Empty*: "A route needs at least one stop before it can be scheduled" with the
add action. Unsaved reordering is protected on exit.

### AD-54 · Stop Detail `P1` `FR-05`
**Actions** — View the stop, its geofence, the routes that use it, the students assigned, and
historical arrival punctuality; edit; view on map.

### AD-55 · Stop Library `P2` `[NEW]`
**Purpose** — Reusable stops shared across routes, so a landmark is defined once.
**Actions** — Manage the master list of physical locations; see which routes use each; merge
duplicates.

### AD-56 · Schedule List `P0` `FR-05`
**Actions** — View route, bus, driver, day, departure, arrival, frequency, active state;
filter by route, bus, driver, day, active; search; open; add; export; bulk activate or
deactivate.

### AD-57 · Schedule Detail `P1` `FR-05`
**Actions** — View the schedule with its route, bus and driver; tabs for generated Trips and
Audit; edit; activate or deactivate; delete.
**Validations** — Deletion is refused while unfinished generated trips exist.

### AD-58 · Add Schedule `P0` `FR-05`
**Actions** — Choose route, bus, driver; set departure and arrival times, day of week,
frequency, validity dates, expected passengers; check conflicts before saving; save.
**Validations** — Arrival after departure; route active and non-empty; bus operational; driver
licence valid; **no overlapping schedule for that bus or that driver on that day within the
same validity window** — the conflicting schedule is named and linked, not merely refused.

### AD-59 · Edit Schedule `P1` `FR-05`
**Validations** — As AD-58, re-checked against the merged result. Editing does not retroactively
alter trips already generated, and the screen says so explicitly.

### AD-60 · Timetable Grid `P1` `[NEW]`
**Purpose** — The whole week's timetable in one view.
**Actions** — Grid of routes against days and times; drag to create or move; copy a day to
another day; bulk shift times; publish changes with an effective date.
**Validations** — Conflict detection runs live during dragging, showing clashes before the drop.

### AD-61 · Service Calendar `P0` `[NEW]`
**Purpose** — Which days the service runs at all.
**Access** — Manager, Super Admin.
**Actions** — Define term dates, holidays, and exam or special-timetable periods; declare an
unplanned service suspension for a date or window; import a holiday list; view the effect on
trip generation.
**Validations** — Declaring a suspension for a date with existing trips shows how many will
cancel and how many people will be notified, and requires confirmation.
**Workflows** — [F-06 Trip Generation](09-system-flows.md#f-06).

### AD-62 · Schedule Variants `P2` `[NEW]`
**Purpose** — Named alternative timetables (exam week, half-day, monsoon timings).
**Actions** — Create a variant; set the date range it supersedes the standard timetable for;
activate; preview which trips change.

---

## F. Operations — Trips, Attendance, Incidents (15 screens)

### AD-63 · Trip List `P0` `FR-06`
**Actions** — View all trips with date, route, bus, driver, planned and actual times, status,
occupancy, delay; filter by date range, status, route, bus, driver, delay, anomaly; search;
open; export; bulk cancel.
**List behaviour** — Defaults to today. Anomalous trips (auto-closed, stalled, attendance
mismatch) are flagged and filterable as a group.

### AD-64 · Trip Detail `P0` `FR-06`
**Actions** — View the full record: schedule, route with stop-by-stop planned versus actual,
bus, driver, passenger manifest, boarding events, position trace, incidents, notifications
sent, delay analysis, audit; replay the trip on a map; export a trip report; cancel; reassign;
force-complete; adjust attendance (creating an attributed correction, never an overwrite).
**Validations** — Status changes follow the trip state machine; terminal trips are read-only
except for attributed corrections.
**States** — *Anomalous*: a banner naming the anomaly and what review is required.

### AD-65 · Create Ad-hoc Trip `P1` `FR-06`
**Purpose** — A trip that is not on the timetable (a field visit, an extra evening run).
**Actions** — Choose route or define a one-off itinerary, bus, driver, date and times; select
passengers; create.
**Validations** — Full conflict, licence, capacity and document checks, identical to scheduled
trips. Creating one on a non-operating day requires an override with a reason.

### AD-66 · Trip Generation Review `P0` `[NEW]`
**Purpose** — Review what the nightly generation produced *before* the day starts.
**Access** — Operations, Manager.
**Actions** — View tomorrow's generated trips; see the exception list (unstaffed, bus
unavailable, driver over hours, expired document, no passengers); fix each inline; re-run
generation for a date; approve the day.
**Validations** — Re-running is idempotent per schedule and date; it never duplicates trips.
**States** — *Exceptions present*: the day cannot be marked approved until every blocking
exception is resolved or explicitly waived with a reason.
**Workflows** — [F-06](09-system-flows.md#f-06).

### AD-67 · Cancel Trip `P1` `FR-06`
**Actions** — Choose a reason from a controlled list plus free text; preview who will be
notified and through which channels; offer an alternative trip in the message; confirm.
**Validations** — Reason mandatory. Cancelling a running trip requires elevated confirmation
and asks what happened to the passengers currently aboard.

### AD-68 · Reassign Trip `P1` `FR-06`
**Actions** — Swap the bus, the driver, or both; see only genuinely eligible candidates; state
a reason; notify affected parties.
**Validations** — Candidate list is filtered by availability, licence validity, duty hours,
document validity and capacity. Eligibility is re-checked at commit, not just at list time.

### AD-69 · Attendance Register `P0` `FR-08`
**Purpose** — Who rode, on which trip, from and to which stop.
**Access** — Operations, Manager, Support (read), Auditor.
**Actions** — View by trip, by student, or by date; see boarding and alighting with stop and
timestamp; identify no-shows and unexpected boardings; add an attributed correction; export.
**Validations** — Records for closed trips are immutable; a correction creates a new record
referencing the original and requires a reason.
**States** — *Discrepancy*: where headcount and boarding events disagree, both numbers are
shown side by side and flagged, never silently reconciled.

### AD-70 · Student Attendance History `P1` `FR-08`
**Actions** — One student's ridership over time; patterns of absence; export for a date range.

### AD-71 · Daily Attendance Summary `P1` `FR-15`
**Actions** — Per route and per trip totals for a date; expected versus actual; unridden
assignments; export.

### AD-72 · Incident List `P0` `FR-11`
**Actions** — View incidents with date, bus, driver, type, severity, status, resulting ticket;
filter by severity, type, status, date, bus, driver; search; open; export.
**List behaviour** — Critical and unresolved incidents sort to the top regardless of the
chosen sort.

### AD-73 · Incident Detail `P0` `FR-11`
**Actions** — View the report, photographs, location on a map, the reporting driver, the trip,
severity and the timeline; triage and set severity; dispatch assistance; open or link a
maintenance ticket; request a replacement bus; notify affected passengers; add follow-up
notes; close with a resolution.
**Validations** — The original report is immutable; follow-up is appended. Closing requires a
resolution note. Severity escalation to `HIGH` or `CRITICAL` automatically takes the bus out
of service and the screen states this before confirmation.
**Workflows** — [F-11](09-system-flows.md#f-11), [F-12 Maintenance](09-system-flows.md#f-12).

### AD-74 · SOS Alerts `P0` `FR-11`
**Purpose** — Panic alerts from drivers. The highest-priority surface in the product.
**Access** — Operations, Manager, Super Admin.
**Actions** — View active SOS with the driver, bus, live position and trip; acknowledge; call
the driver; dispatch emergency services; notify guardians of passengers aboard; escalate;
resolve with an account of what happened.
**Validations** — Cannot be dismissed without acknowledgement and a resolution note.
**States** — An active SOS produces a persistent, unmissable indicator on **every** staff
screen until acknowledged, plus audible alert on the operations console.

### AD-75 · Replacement Bus Requests `P0` `FR-12`
**Actions** — View requests with the failed bus, its trip, passengers affected and recommended
replacements ranked by proximity and availability; approve; reject with a reason; choose an
alternative; dispatch; track the replacement's arrival.
**Validations** — The chosen replacement must be available, documented, and have capacity for
the transferred passengers. Above a configured cost threshold, manager approval is required
and the screen shows the pending state rather than acting.
**Workflows** — [F-13 Replacement](09-system-flows.md#f-13).

### AD-76 · Consolidation Recommendations `P1` `FR-13`
**Actions** — View proposed merges with source and target trips, combined occupancy, estimated
fuel saved, added distance and delay per passenger; approve; reject with a reason; adjust the
target; preview passenger impact before committing.
**Validations** — Combined passengers must not exceed the target bus's capacity; every
affected passenger must be notified before the merge takes effect; a merge cannot be approved
for a trip already running beyond a cutoff point.
**Workflows** — [F-14 Consolidation](09-system-flows.md#f-14).

### AD-77 · Passengers Left Behind `P1` `[NEW]`
**Purpose** — The stranded queue. A safety surface, not an analytics one.
**Actions** — View students flagged as not picked up, with stop, trip and time; contact them
and their guardians; dispatch an alternative; record the resolution.
**States** — Unresolved entries escalate on a timer and appear on the dashboard exception list.

---

## G. Maintenance (7 screens)

### AD-78 · Maintenance Queue `P0` `FR-14`
**Access** — Maintenance, Manager, Operations (read).
**Actions** — View tickets by priority with bus, issue, source incident, age, assignee,
status; filter; search; open; create manually; assign; bulk update status.
**List behaviour** — Sorted by priority then age. Tickets blocking a bus from service are
flagged as such.

### AD-79 · Maintenance Ticket Detail `P0` `FR-14`
**Actions** — View the issue, the originating incident, vehicle history, diagnosis, parts,
labour, cost, and the timeline; update status; assign a technician or workshop; record parts
and cost; attach photographs and invoices; **certify the bus fit and return it to service**;
close.
**Validations** — Returning a bus to service is restricted to Maintenance and Manager and
requires the ticket to be complete. Closing requires a resolution.
**States** — *Blocking*: banner stating the bus is out of service and how many scheduled trips
are affected.

### AD-80 · Create Maintenance Ticket `P1` `FR-14`
**Actions** — Choose bus, category, priority, description; attach photographs; decide whether
it takes the bus out of service now.
**Validations** — Taking a bus out of service with an active trip is refused and names the trip.

### AD-81 · Preventive Maintenance Schedule `P1` `[NEW]`
**Actions** — Define service intervals by distance and by time per vehicle class; view what is
due and overdue; generate tickets from the schedule; record completion.
**States** — Overdue preventive service raises an alert and, past a configurable grace, blocks
assignment.

### AD-82 · Service History `P1` `FR-14`
**Actions** — Full maintenance record per bus; cost over time; recurring-fault analysis; export.

### AD-83 · Parts & Inventory `P2` `[NEW]`
**Actions** — Track parts stock, consumption and reorder levels; link parts to tickets.

### AD-84 · Workshop / Vendor Management `P2` `[NEW]`
**Actions** — Manage workshops, rates and turnaround performance; assign tickets to vendors.

---

## H. Finance `[NEW]` (8 screens)

### AD-85 · Fee Structures `P1` `[NEW]`
**Access** — Finance, Manager, Super Admin.
**Actions** — Define fees by route, distance band, term and concession category; set validity
periods; version a structure rather than editing history.
**Validations** — Overlapping validity for the same category is refused; amounts non-negative.

### AD-86 · Transport Passes `P0` `[NEW]`
**Purpose** — The entitlement register that gates ridership.
**Actions** — View passes with student, route, validity, status; issue; renew; suspend;
cancel with refund handling; bulk-renew a cohort at term rollover; export.
**Validations** — A pass cannot be issued to an inactive student; validity dates must be
coherent; issuing overlapping passes to one student is refused.
**States** — Expiring within 14 days is highlighted; expired passes drive the "riding without
entitlement" report.

### AD-87 · Payments `P1` `[NEW]`
**Actions** — Record payments (online, cash, transfer, cheque); reconcile against gateway
settlements; issue receipts; handle failures and refunds.
**Validations** — Amount must match the fee due or be explicitly recorded as partial; a
reconciled payment cannot be edited, only adjusted with an attributed correction.

### AD-88 · Outstanding Dues `P1` `[NEW]`
**Actions** — View unpaid and partially paid students; age the debt; send reminders in bulk;
flag for service suspension; export for the accounts system.
**Validations** — Suspending transport for non-payment requires manager approval and a notice
period; a student is never removed from a bus mid-journey for a billing reason.

### AD-89 · Concessions & Waivers `P2` `[NEW]`
**Actions** — Define and grant concessions; record the justification and approver.

### AD-90 · Revenue Report `P1` `[NEW]` `FR-15`
**Actions** — Collections by period, route and category; forecast against the fee register;
export.

### AD-91 · Refunds `P2` `[NEW]`
**Actions** — Process refunds for withdrawal, service failure or overpayment; approval chain;
audit trail.

### AD-92 · Finance Settings `P2` `[NEW]`
**Actions** — Currency, tax treatment, receipt numbering, gateway configuration, reminder
cadence.

---

## I. Communication (4 screens)

### AD-93 · Announcements Manager `P1` `FR-10`
**Actions** — Create, schedule, publish, expire and pin announcements; target by role, route,
department or individual; require acknowledgement; view read and acknowledgement rates.
**Validations** — Audience non-empty; expiry after publication; a targeted count is shown
before sending.

### AD-94 · Notification Log `P1` `FR-10`
**Access** — Operations, Manager, Support, Auditor.
**Actions** — View every notification dispatched with recipient, channel, template, trigger,
status and failure reason; filter; search; resend a failed notification; export.
**States** — Channel-level failure rates are summarised at the top; a failing channel is an
operational incident, not a statistic.

### AD-95 · Notification Templates `P2` `FR-10`
**Actions** — Edit message templates per event and channel; manage translations; preview with
sample data; version.
**Validations** — Required placeholders must be present; a template that drops the stop name
from an "approaching" message is rejected.

### AD-96 · Communication Preferences Policy `P2` `[NEW]`
**Actions** — Set institution-wide defaults and quiet hours; declare which categories are
non-mutable safety classes.

---

## J. Reports & Analytics (7 screens)

### AD-97 · Report Library `P1` `FR-15`
**Actions** — Browse standard reports by category; run with parameters; schedule recurring
delivery; view past runs; download.

### AD-98 · Operational Report `P1` `FR-15`
Trips run, completed, cancelled, on-time performance, average delay, by route and period.

### AD-99 · Fleet Utilisation Report `P1` `FR-15`
Distance, hours in service, idle time, fuel, cost per kilometre, per vehicle.

### AD-100 · Occupancy Report `P1` `FR-15`
Seats offered against seats used, by trip, route and time band; identifies consolidation and
capacity opportunities.

### AD-101 · Incident & Safety Report `P1` `FR-15`
Incidents by type, severity, vehicle, driver and location; repeat-fault and hotspot analysis.

### AD-102 · Custom Report Builder `P2` `[NEW]`
**Actions** — Choose entity, fields, filters, grouping and visualisation; save; share; schedule.
**Validations** — Field availability respects the runner's permissions — a custom report cannot
be used to reach data the user cannot see on screen.

### AD-103 · Scheduled Reports `P2` `[NEW]`
**Actions** — Manage recurring report delivery, recipients, format and cadence.

---

## K. Administration (8 screens)

### AD-104 · User Management `P0` `FR-01`
**Access** — Super Admin; Manager for non-staff roles.
**Actions** — All accounts across roles; activate and deactivate; force reset; revoke
sessions; change role; view permissions; export.
**Validations** — Cannot act destructively on one's own account; two-super-admin minimum
enforced; deactivation immediately revokes that user's sessions.

### AD-105 · Roles & Permissions `P1` `[NEW]`
**Actions** — View the role–permission matrix; create custom roles; grant and revoke
permissions; see how many users hold each role.
**Validations** — A change that would leave zero users able to perform a critical function is
refused with an explanation.

### AD-106 · System Settings `P1`
**Actions** — Institution identity, timezone, locale, operating hours, GPS ping interval,
geofence default radius, delay thresholds, capacity safety margin, trip start window,
auto-close buffer, retention periods.
**Validations** — Each setting is range-checked; changes that affect live operations warn and
are audit-logged with old and new values.

### AD-107 · Integrations `P1` `[NEW]`
**Actions** — Configure and test maps, SMS, push, email, payment gateway and SSO; rotate
credentials; view health and recent failures.
**States** — Each integration shows live status; a failing one links to its effect on
operations.

### AD-108 · Audit Log `P0` `FR-15`
**Access** — Super Admin (full), Auditor (scoped), Manager (own domain).
**Actions** — View every recorded action with actor, action, entity, before and after values,
timestamp, address and correlation identifier; filter by actor, entity, action, date; search;
export an evidence pack.
**Validations** — Read-only. No deletion, no editing, by anyone, ever. Exports are themselves
logged.

### AD-109 · Data Management `P1` `[NEW]`
**Actions** — Run and schedule retention purges; archive completed terms; view storage; run
bulk exports; fulfil subject-access and erasure requests.
**Validations** — Every destructive operation previews exactly what it will remove, requires
typed confirmation, and cannot run in a way that breaks referential history.

### AD-110 · System Health `P1` `[NEW]`
**Actions** — Queue depth, job failures, GPS ingest rate, notification delivery rate, error
rate, dependency status; retry failed jobs.

### AD-111 · Impersonation `P2` `[NEW]`
**Purpose** — Reproduce a user's problem as that user.
**Access** — Super Admin only.
**Actions** — Start an impersonation session with a stated reason; a persistent banner is
shown throughout; end.
**Validations** — Read-only by default; write actions during impersonation require a further
explicit step. Every impersonated action is logged as *acted-by X as Y*, never as Y alone.
The impersonated user is notified.
