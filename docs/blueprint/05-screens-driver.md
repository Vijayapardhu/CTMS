# Phase 2 — Driver App Screens

27 screens. Mobile. The design constraint that governs everything here: **the driver is
standing at a bus door with one hand free, in daylight glare, possibly with no signal, and
students are waiting.** Depth of navigation is a safety issue.

Conventions from [02-screens-conventions.md](02-screens-conventions.md) apply, with the
offline rules being load-bearing rather than incidental.

---

## A. Duty & trip lifecycle (11 screens)

### DR-01 · Driver Home / Today `P0` `FR-06`

**Purpose** — Everything the driver needs for the next action, on one screen.
**Access** — Driver, own duty only.
**Entry** — Sign-in landing; app icon; notification tap.
**Exit** — Pre-trip inspection `DR-03` · Active trip `DR-05` · Trip detail `DR-02` · Profile.

**Actions**
- View today's assigned trips in order: route, bus, departure, arrival, passenger count
- See the next trip prominently with a countdown to departure
- Start the next trip (the single primary action on the screen)
- View duty status and change it (available / off duty)
- See the assigned bus and its details
- View unread notifications and announcements
- Access SOS from a persistent control present on every screen in the app
- Pull to refresh; view sync state and pending offline queue depth

**Validations** — The start action is disabled, **with the reason shown**, when: the
inspection is incomplete, it is too early relative to the departure window, the licence has
expired, the bus is not available, or duty-hour limits are reached. A disabled button with no
explanation is a support call.

**States**
- *Empty — no duty today*: states it plainly, shows the next scheduled duty date
- *Offline*: cached duty is shown with its age; starting a trip offline is permitted and
  queued, because refusing to let a bus leave because of a signal problem is worse than
  reconciling later
- *Pending sync*: a badge with the queued-action count and a manual sync control

**Workflows** — [F-07 Trip Execution](09-system-flows.md#f-07).

---

### DR-02 · Trip Detail (before start) `P0` `FR-06`
**Purpose** — Review the run before committing to it.
**Actions** — View route with all stops in order and a map preview; expected passengers per
stop; scheduled times; bus details; report a problem before starting; start.
**States** — *Offline*: route and stops are cached at duty assignment, not fetched at start
time, precisely because signal at the depot is unreliable.

### DR-03 · Pre-Trip Inspection `P0` `[NEW]`
**Purpose** — The legally and operationally required vehicle check. **A trip cannot start
without it.**
**Access** — Driver, for their assigned bus.
**Entry** — From DR-01 when attempting to start; scheduled prompt before first duty.
**Exit** — Back to DR-01 with the trip now startable, or into an incident report on failure.

**Actions** — Work through the checklist (brakes, lights, tyres, mirrors, horn, doors,
emergency exit, first-aid kit, extinguisher, fuel, fluids, cleanliness); mark each pass or
fail; attach a photograph to any failure; add notes; record the odometer; submit.

**Validations** — Every item must be answered; a failure requires a note, and a photograph for
safety-critical items; the odometer must be at or above the last recorded value.

**States**
- *Fail on a safety-critical item*: the trip is **blocked**, a maintenance ticket opens
  automatically, and operations is alerted immediately — while there is still time to
  substitute a bus
- *Fail on a non-critical item*: a ticket opens, the trip may proceed, the driver is told this
- *Offline*: completed locally and queued; the trip may start

### DR-04 · Start Trip Confirmation `P0` `FR-06`
**Actions** — Confirm the bus (with an option to substitute the actual vehicle if it differs
from the assignment), confirm the route, confirm the start; begin.
**Validations** — Substituting a bus re-checks availability, documents and capacity, and
recalculates the manifest limit.
**States** — *Success*: position streaming begins, all passengers and guardians are notified,
and the app switches to DR-05.

### DR-05 · Active Trip `P0` `FR-06` `FR-07` `FR-08`
**Purpose** — The in-cab operating screen. While a trip runs, this is effectively the app.
**Access** — Driver, own running trip.
**Exit** — Only by ending the trip, or into a modal (incident, SOS, stop detail).

**Actions**
- See the current stop, the next stop and the distance and time to it
- Mark arrival at a stop (or accept the automatic geofence arrival)
- **+1 / −1 passenger count** — the two largest touch targets on the screen
- View the current occupancy against capacity
- View the boarding list for the current stop
- Mark a stop as skipped, with a reason
- Report an incident; raise SOS
- View the route map with remaining stops
- End the trip
- See connection and sync status at all times

**Validations** — `+1` is refused at capacity, with a clear message and the option to record
"passengers left behind"; `−1` is refused below zero; arrival cannot be marked for a stop
already passed without an explicit correction; ending is refused before the final stop unless
a reason is given.

**States**
- *Offline*: fully functional. Every action queues. The screen shows the queue depth and the
  time since last sync, prominently but not alarmingly — this is expected operation
- *GPS unavailable*: the trip continues; stop arrivals become fully manual; the driver is told
  that students are seeing an estimated rather than live position
- *At capacity*: the boarding control changes appearance before it is pressed, so the driver
  knows before the student is at the step

**Workflows** — [F-07](09-system-flows.md#f-07), [F-09 Attendance](09-system-flows.md#f-09).

### DR-06 · Stop Detail (in trip) `P0` `FR-08`
**Actions** — View the students expected at this stop; mark individuals boarded or absent
where named boarding is enabled; adjust the count; add a note; confirm departure from the stop.
**Validations** — A named student cannot be marked boarded twice on the same trip. Marking a
student boarded who is not on the manifest opens a confirm-and-record path rather than a refusal.

### DR-07 · Passenger Manifest `P1` `FR-08`
**Actions** — The full list for the trip, grouped by stop, with boarding state; search;
mark boarded or absent.
**Access notes** — Shows only the name and stop. No address, no phone, no guardian details —
a driver needs to know who to expect, not where they live.

### DR-08 · End Trip `P0` `FR-06`
**Actions** — Confirm the final headcount, record the closing odometer, note any issues,
confirm completion.
**Validations** — A headcount that disagrees with the boarding events requires an explanation;
the mismatch is recorded rather than silently resolved. Ending early requires a reason.
**States** — *Success*: trip closes, bus and driver return to available, guardians receive
arrival confirmation, attendance freezes.

### DR-09 · Trip Summary `P1` `FR-06`
**Actions** — Post-trip recap: duration, distance, stops served, passengers carried, delays,
incidents. Acknowledge and return home.

### DR-10 · Trip History `P1`
**Actions** — Past trips with date, route, duration, passengers; filter by date range; open
any for detail.
**List behaviour** — Infinite scroll, newest first. Cached for offline reading.

### DR-11 · Route Preview `P1`
**Actions** — Study any assigned route in advance: stops in order, map, distances, timings,
landmark notes. Available offline.

---

## B. Incidents & safety (5 screens)

### DR-12 · SOS `P0` `FR-11`
**Purpose** — Summon help immediately.
**Access** — Driver. Reachable from **every** screen in the app, including while offline.
**Entry** — A persistent control, deliberately requiring a deliberate gesture (press and hold)
to avoid pocket activation while remaining reachable in one action.

**Actions** — Trigger SOS; choose a category (medical, accident, security, breakdown, other)
if there is time, or send with no category; call the emergency contact directly; cancel a
false alarm within a short window.

**Validations** — Press-and-hold prevents accidental triggering. Cancellation within the grace
window still records the event; a cancelled SOS is never erased.

**States**
- *Sent*: unmistakable confirmation, the operations acknowledgement state, and a live channel
  to the controller
- *Offline*: falls back to a direct phone call and an SMS to the operations number, and queues
  the in-app alert. An SOS must never depend on data connectivity

**Workflows** — [F-11 Emergency](09-system-flows.md#f-11).

### DR-13 · Report Incident `P0` `FR-11`
**Actions** — Choose type (breakdown, accident, tyre, engine, battery, passenger, road, other);
set severity; describe; attach photographs; confirm the auto-captured location; state whether
the bus can continue; submit.
**Validations** — Type and severity required; description required; a photograph required for
`HIGH` and `CRITICAL`; location captured automatically with a manual override for GPS failure.
**States** — *Submitted*: the driver is told what happens next — ticket opened, operations
notified, replacement requested if severity warrants. Ambiguity here causes drivers to
re-report the same fault. *Offline*: queued with its photographs; the driver is told it will
send on reconnect, and given the operations phone number for anything urgent.

### DR-14 · My Incidents `P1` `FR-11`
**Actions** — Incidents this driver reported, with status and resolution; open for detail; add
follow-up notes.

### DR-15 · Incident Detail `P1` `FR-11`
**Actions** — Read the report, its resulting maintenance ticket, and the resolution timeline;
append a follow-up note. The original submission is immutable.

### DR-16 · Emergency Contacts `P0`
**Actions** — Transport office, operations controller, emergency services, workshop — one-tap
dial. **Available offline and without an active session.**

---

## C. Vehicle (4 screens)

### DR-17 · My Bus `P1` `FR-02`
**Actions** — View the assigned vehicle: registration, model, capacity, fuel type, document
validity, service status, current odometer; report a defect.
**States** — *Unassigned*: explains that operations assigns vehicles and how to ask.

### DR-18 · Vehicle Checklist History `P2` `[NEW]`
**Actions** — Past inspections submitted by this driver, with outcomes.

### DR-19 · Fuel Entry `P2` `[NEW]`
**Actions** — Record refuelling: odometer, quantity, cost, station, receipt photograph.
**Validations** — Odometer monotonic; quantity within tank capacity.
**States** — Works offline and queues.

### DR-20 · Report Vehicle Defect `P1` `FR-14`
**Actions** — Report a non-urgent fault outside a trip; category, description, photograph;
state whether it is safe to drive.
**Validations** — "Not safe to drive" immediately takes the bus out of service and alerts
operations, and the screen says so before submission.

---

## D. Schedule & personal (7 screens)

### DR-21 · My Schedule `P1` `FR-05`
**Actions** — Weekly and monthly duty view; upcoming assignments; export to device calendar.
**States** — Published roster changes since last view are highlighted.

### DR-22 · Leave Request `P1` `[NEW]`
**Actions** — Request leave with type, dates and reason; view status; cancel a pending request.
**Validations** — Dates cannot be in the past; overlapping requests refused; requesting leave
that collides with an assigned duty warns and states that coverage must be arranged.

### DR-23 · My Duty Hours `P1` `[NEW]`
**Actions** — Hours driven today, this week; rest periods; distance to the regulatory ceiling.
**States** — Approaching the limit warns; at the limit, trip start is blocked and this screen
is where the driver learns why.

### DR-24 · My Profile `P1`
**Actions** — View own record: licence, class, expiry, employment, contact, emergency contact;
edit contact details only; request correction of compliance fields.
**Validations** — Licence data is not self-editable. Licence expiring within 30 days is warned
here and by notification.

### DR-25 · My Performance `P2` `FR-15`
**Actions** — Punctuality, trips completed, incidents, fuel efficiency, feedback, over a stated
period, with fleet averages for context.

### DR-26 · Notifications `P0` `FR-10`
Driver-scoped instance of `SH-15`: duty assignments, roster changes, cancellations, alerts.

### DR-27 · Settings `P2`
**Actions** — Language; high-contrast and large-text modes; background-location permission
status with an explanation of why it is required; battery-optimisation guidance; sync
controls; sign out.
**States** — When background location permission is missing or restricted, a persistent,
explanatory warning appears — this is the single most common cause of a bus appearing to
vanish from the live map, and it must be diagnosable by the driver themselves.
