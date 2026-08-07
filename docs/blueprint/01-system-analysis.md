# Phase 1 — Complete System Analysis

---

## 1. System overview

### 1.1 What the system is

CTMS is the operational system of record for a college's student transport service. It owns
four things end to end:

1. **The plan** — which buses run on which routes, at which times, with which drivers, and
   which students are entitled to ride them.
2. **The execution** — turning the plan into concrete daily trips, tracking those trips live,
   and recording who boarded and who got off.
3. **The exceptions** — breakdowns, accidents, delays, absent drivers, no-show buses,
   route closures, and the substitutions and notifications each of those forces.
4. **The record** — an auditable history of every operational decision, sufficient to answer
   "where was my child at 4:40pm and who was driving" months after the fact.

### 1.2 Who it serves and what they need

| Constituency | What they need from the system | Consequence of failure |
|---|---|---|
| Students | To know their bus is coming, when, and to get on it | Missed classes; stranded after dark |
| Parents | To know their child boarded and got off, safely | Panic; loss of institutional trust |
| Drivers | A clear list of what to drive, where, and when; a way to report trouble | Wrong route; unreported breakdown |
| Transport managers | Live visibility of the fleet, and control over exceptions | Blind operations; fuel waste |
| Support desk | To answer "where is bus 12" in under thirty seconds | Phone queue; escalation |
| Maintenance | A queue of what is broken, prioritised | Unsafe vehicles in service |
| Finance | Who has paid, who has not, who may ride | Revenue leakage; unfunded service |
| College leadership | Cost, utilisation, safety and punctuality trends | Bad capital decisions |
| Regulators / insurers | Licence validity, incident records, fitness certificates | Fines; voided insurance |

### 1.3 Operating characteristics

These shape nearly every design decision below and should be treated as constraints:

- **Extreme demand concentration.** 80–90% of all system activity happens in two windows of
  roughly ninety minutes each: the morning inbound run and the evening outbound run. The
  system is nearly idle the rest of the day. Capacity planning, notification batching and
  report scheduling must all respect this shape.
- **The driver's device is the weakest link.** It is a personal phone, on a moving vehicle,
  behind a windscreen, on cellular data, running down its battery. It **will** lose
  connectivity mid-route, and it will do so in exactly the places students are waiting.
  Offline tolerance is not a nice-to-have; it is the core reliability requirement.
- **Safety-critical, not mission-critical.** A social network that loses data annoys people.
  A transport system that loses an attendance record cannot tell a parent where their child
  is. Attendance and incident data must be durable and reconstructable.
- **The plan is stable, the execution is not.** Routes and timetables change once a term.
  Trips change every single day — sick drivers, broken buses, closed roads, exam schedules.
  The system must make the stable part easy to set up once, and the volatile part easy to
  fix in ninety seconds on a phone while standing in a bus bay.
- **Low-trust user population for privilege.** Students are motivated to grant themselves
  tickets they have not paid for and to see data about other students. Every entitlement must
  be server-enforced.

---

## 2. User roles

The SRS defines three roles. A production system needs ten. The three original roles remain,
subdivided by privilege; the rest are new.

### 2.1 Role catalogue

| Role | Type | In SRS? | Population | Device |
|---|---|---|---|---|
| Super Administrator | Staff | Partial | 1–3 | Web |
| Transport Manager | Staff | Yes (ADMIN) | 2–5 | Web |
| Operations Controller | Staff | Partial | 3–10 | Web + tablet |
| Support Desk Agent | Staff | **`[NEW]`** | 2–8 | Web |
| Maintenance Coordinator | Staff | **`[NEW]`** | 1–4 | Web |
| Finance Officer | Staff | **`[NEW]`** | 1–3 | Web |
| Auditor / Read-only | Staff | **`[NEW]`** | 1–5 | Web |
| Driver | Field | Yes | 20–200 | Mobile |
| Student | End user | Yes | 500–20,000 | Mobile + web |
| Parent / Guardian | End user | **`[NEW]`** | 500–20,000 | Mobile |
| Gate / Security Officer | Field | **`[NEW]`** | 2–10 | Tablet / kiosk |
| System (automated) | Non-human | Implicit | — | — |

### 2.2 Responsibilities in detail

#### Super Administrator

Owns the *configuration of the system itself*, not day-to-day operations.

- Creates and deactivates all staff accounts; assigns and revokes roles
- Defines the role–permission matrix and any custom roles
- Configures institution-wide settings: academic calendar, term dates, holiday list,
  operating hours, geofence radius defaults, GPS ping interval, notification quiet hours
- Manages integrations and credentials: maps provider, SMS gateway, push service, payment
  gateway, SSO/identity provider
- Sets data-retention policy per data class and executes or schedules purges
- Is the only role that can read the full audit log unfiltered, and the only role that can
  perform a bulk destructive action (mass delete, mass deactivate, data export of the whole
  student body)
- **Cannot** be the only holder of the role — the system enforces a minimum of two active
  super administrators, because a single locked-out super admin is an unrecoverable state

#### Transport Manager

Owns the *plan*.

- Creates, edits and retires routes and their stops
- Builds the weekly timetable: which bus, which driver, which route, which days, what times
- Approves or rejects operations decisions that cost money or change service: replacement bus
  assignments, bus consolidations, route changes, overtime
- Manages the fleet roster: commissioning new buses, retiring old ones, setting capacity
- Manages the driver roster: hiring, licence records, duty rosters, leave approval
- Sets service policy: how full is too full, how late is late, when to cancel a run
- Reviews operational and cost reports; owns the transport budget line

#### Operations Controller

Owns the *day*. This is the person watching the live map during the morning run.

- Monitors the live fleet: every running trip, its position, its delay, its occupancy
- Starts, cancels and reassigns trips on the day
- Reassigns a driver or a bus when someone calls in sick or something breaks
- Triages incoming incidents and SOS alerts; decides severity; dispatches help
- Requests a replacement bus (approval required from Transport Manager above a cost threshold)
- Publishes service announcements ("Route 7 delayed 20 minutes, flooding on Main St")
- Acknowledges and closes alerts
- **Cannot** change the underlying timetable, routes, or anyone's pay — same-day changes only

#### Support Desk Agent

Owns *questions*.

- Looks up any student, parent, trip, bus or route to answer an enquiry
- Sees live bus position and ETA on behalf of a caller
- Reads (never writes) attendance history to answer "did my child board"
- Logs the enquiry and its resolution against the student's record
- Raises a ticket to Operations when something needs action
- Resets a user's password and resends invitations; **cannot** change a user's role, ticket
  entitlement, or any operational data

#### Maintenance Coordinator

Owns *vehicle health*.

- Works the maintenance queue: tickets opened automatically by incidents and manually by staff
- Schedules service, assigns a workshop or technician, records parts and cost
- Moves a bus between `MAINTENANCE` and `AVAILABLE` — the only role besides Transport Manager
  that can certify a bus fit to return to service
- Maintains the preventive-maintenance calendar (by odometer and by date) and the document
  register: fitness certificate, insurance, pollution certificate, permit
- Raises a blocking alert when a vehicle's statutory document is about to expire

#### Finance Officer **`[NEW]`**

Owns *entitlement and money*.

- Defines transport fee structures by route, distance band, term and concession category
- Records payments; issues, renews, suspends and refunds transport passes
- Sees who has an unpaid balance and who is riding without a valid pass
- Produces revenue, collection and outstanding-dues reports
- **Cannot** see live GPS position or attendance detail — financial staff have no operational
  need to know where a specific child is right now, and granting it widens the exposure of
  minors' location data for no benefit

#### Auditor / Read-only **`[NEW]`**

- Read access across operational data with no write capability anywhere
- Reads the audit trail, filtered to their scope
- Exports evidence packs for a date range, an incident, a vehicle or a person
- Every read by an auditor is itself logged

#### Driver

Owns *the vehicle and the people in it, for the duration of a trip*.

- Sees today's duty: which bus, which route, which times
- Performs the pre-trip vehicle inspection checklist and cannot start without completing it
- Starts, runs and ends trips; streams position automatically while a trip is running
- Marks arrival at each stop; counts passengers on and off
- Reports incidents with photograph, location and severity; raises an SOS
- Records fuel, odometer and end-of-day condition
- Requests leave; views their own duty history and their own record
- **Cannot** see other drivers' records, change their own licence data, assign themselves a
  bus, alter attendance after a trip is closed, or see student personal data beyond the
  boarding list for their own current trip

#### Student

- Sees their assigned route, stop, timetable and pass status
- Tracks their bus live and sees the ETA to their stop
- Receives notifications: trip started, bus approaching, delayed, cancelled, replaced
- Marks themselves absent in advance ("not riding tomorrow"), which feeds occupancy planning
- Views their own attendance and trip history
- Reports a problem or gives feedback on a trip
- Requests a route or stop change; requests a pass renewal
- **Cannot** see other students, live position of buses they are not entitled to ride, or
  driver personal data

#### Parent / Guardian **`[NEW]`**

- Linked to one or more students, by explicit verified consent, not by assertion
- Sees live tracking and ETA for their child's bus only, during that child's active trips
- Receives boarding and alighting confirmations, delay and incident notifications
- Marks their child absent; approves or refuses route change requests
- Views attendance history and pays transport fees
- **Cannot** see any other child, contact the driver directly through the system, or see the
  bus outside their child's service window

#### Gate / Security Officer **`[NEW]`**

- Records buses entering and leaving campus at the gate
- Verifies a driver's identity against the trip assignment
- Views the manifest of the arriving bus (headcount, not names) for emergency response
- Raises a campus-side incident

#### System (automated actor)

Documented as a role because it *acts*, and its actions must be attributable in the audit
trail exactly like a human's.

- Generates trips from the timetable nightly
- Evaluates geofences and emits "bus approaching your stop" notifications
- Recalculates ETAs on every position update
- Detects delay thresholds and emits delay notifications
- Detects a stalled trip (no GPS for N minutes) and raises an operations alert
- Opens a maintenance ticket automatically when an incident is reported
- Proposes replacement buses and consolidation opportunities
- Auto-closes trips abandoned past a cutoff
- Expires passes, flags expiring licences and certificates
- Runs scheduled reports and retention purges

### 2.3 Permission model

Two axes, both enforced server-side, both required.

**Axis 1 — role.** Coarse. "Is this caller a driver at all?"

**Axis 2 — relationship to the record.** Fine. "Is this *their* trip / *their* child /
*their* own profile?" A valid token plus someone else's identifier in the URL must never
return data.

The second axis is where transport systems leak. Rules that must hold without exception:

- A driver may read the boarding list **only** for a trip currently assigned to them, and
  **only** while that trip is `RUNNING` or within a grace window after it closes
- A student may read only their own records
- A parent may read only records of students they are **verified** against, and only for
  data classes that link has been granted
- Location history of a minor is readable by: that student, their verified guardians,
  operations staff on duty, and auditors. Nobody else, ever, including finance and support
- Support agents see attendance *summaries* (boarded yes/no, which stop) but not continuous
  location traces

**Break-glass access.** In a genuine emergency, operations staff need data they normally
cannot see. This is a real requirement and must be designed rather than achieved by handing
out permanent privilege. Break-glass access: is explicitly invoked, requires a stated reason,
is time-boxed to a short window, notifies the super administrator immediately, and produces a
prominent audit entry. It is never silent.

---

## 3. Business rules

Rules are grouped by the object they constrain. Each is stated as an invariant the system
enforces, not as a suggestion.

### 3.1 Identity and access

| # | Rule |
|---|---|
| BR-A1 | Every account has exactly one role. Role changes are an explicit, audited administrative act |
| BR-A2 | Self-service registration can create a student account only. Any other role requires an authenticated administrator |
| BR-A3 | A deactivated account is refused on the very next request, not when its session expires |
| BR-A4 | Changing a password invalidates every existing session on every device |
| BR-A5 | Failed sign-in responses are identical for "no such account" and "wrong password" |
| BR-A6 | A user cannot deactivate or delete their own account through an administrative screen |
| BR-A7 | At least two super administrators must remain active at all times |
| BR-A8 | A parent–student link requires verification by the institution or by the student; a parent asserting a relationship is not sufficient |
| BR-A9 | Staff accounts require multi-factor authentication; end-user accounts may opt in |

### 3.2 Fleet

| # | Rule |
|---|---|
| BR-B1 | A bus's status follows the defined state machine; arbitrary jumps are rejected. In particular a `BREAKDOWN` bus reaches `AVAILABLE` only via `MAINTENANCE` |
| BR-B2 | A bus assigned to an unfinished trip cannot be retired, taken offline, or put into maintenance without first reassigning that trip |
| BR-B3 | Seating capacity cannot be reduced below the booked passenger count of any active trip |
| BR-B4 | A bus with an expired fitness certificate, insurance or permit cannot be assigned to a trip — this is a legal bar, not a warning |
| BR-B5 | A bus is never deleted, only retired, because trips, incidents and attendance reference it |
| BR-B6 | One bus, one driver, at one time |

### 3.3 Drivers

| # | Rule |
|---|---|
| BR-D1 | A driver with an expired licence cannot be assigned a bus or start a trip |
| BR-D2 | A driver may hold at most one active trip at a time |
| BR-D3 | A driver on an active trip cannot go on leave or off duty until it ends |
| BR-D4 | Duty-hour ceilings are enforced: maximum continuous driving, maximum daily hours, minimum rest between duties. Exceeding them blocks assignment and raises a compliance alert |
| BR-D5 | Drivers cannot edit their own licence or compliance records |
| BR-D6 | A driver must complete the pre-trip inspection before a trip can start |
| BR-D7 | A driver reporting a critical incident is immediately removed from assignable status pending review |

### 3.4 Students and entitlement

| # | Rule |
|---|---|
| BR-S1 | A student may hold at most one active transport assignment (route + pickup stop) at a time |
| BR-S2 | Transport can be assigned only to an `ACTIVE` student holding a valid, unexpired pass |
| BR-S3 | The pickup stop must belong to the assigned route and must permit pickup |
| BR-S4 | Suspending or deactivating a student clears their transport assignment |
| BR-S5 | Students cannot grant themselves a pass or extend their own expiry |
| BR-S6 | A route cannot be retired while students are still assigned to it |
| BR-S7 | Assignments to a route must not exceed the seating capacity of the buses scheduled on it, minus a configurable safety margin. Over-subscription requires explicit override with a reason |

### 3.5 Network — routes, stops, schedules

| # | Rule |
|---|---|
| BR-N1 | A route's stops form a contiguous 1..N sequence with no gaps and no duplicates |
| BR-N2 | A stop cannot be removed while students are assigned to it |
| BR-N3 | A route with no stops cannot be scheduled |
| BR-N4 | Only an `ACTIVE` route can be scheduled or carry passengers |
| BR-N5 | On any given weekday, a bus cannot appear in two overlapping schedule windows; the same holds for a driver |
| BR-N6 | Arrival time must be later than departure time; overnight runs are modelled as two schedules |
| BR-N7 | Changing a route or timetable that affects assigned students requires notifying them, and the change takes effect from a stated date, not immediately |
| BR-N8 | Stop coordinates must fall inside the institution's configured service area |

### 3.6 Trips

| # | Rule |
|---|---|
| BR-T1 | Trip status moves forward only: `SCHEDULED → RUNNING → COMPLETED`. It may be cancelled before completion. Terminal states never reopen |
| BR-T2 | A trip can only start with an available bus, an available licensed driver, and a completed inspection |
| BR-T3 | A trip cannot start more than a configured window before its scheduled departure |
| BR-T4 | Only the assigned driver, or an operations controller, can start or end a trip |
| BR-T5 | Occupancy must never exceed the bus's seating capacity. The boarding action is refused at capacity |
| BR-T6 | Passenger count cannot go below zero |
| BR-T7 | Attendance cannot be altered after a trip closes; corrections are new, attributed adjustment records that preserve the original |
| BR-T8 | A trip with no GPS update for the configured threshold is flagged stalled and raises an operations alert |
| BR-T9 | A trip left running past its scheduled arrival plus the completion buffer is auto-closed and flagged for review |
| BR-T10 | Cancelling a trip requires a reason and notifies every assigned passenger |

### 3.7 Tracking and location

| # | Rule |
|---|---|
| BR-G1 | Position is accepted only from the assigned driver of a `RUNNING` trip |
| BR-G2 | Positions failing plausibility checks — impossible speed, impossible jump, outside the service region, accuracy worse than threshold — are rejected and logged, not stored as truth |
| BR-G3 | Position ingest is rate-limited per device |
| BR-G4 | Live position is visible only to entitled viewers, and only while the trip is running |
| BR-G5 | Location history is retained for the operational window then aggregated; raw traces of minors are purged on the retention schedule |
| BR-G6 | Loss of GPS does not stop the trip; the driver continues on a degraded, manual path |

### 3.8 Incidents, maintenance, replacement

| # | Rule |
|---|---|
| BR-I1 | Every reported incident automatically opens a maintenance ticket |
| BR-I2 | A `HIGH` or `CRITICAL` incident immediately takes the bus out of service and triggers a replacement recommendation |
| BR-I3 | An SOS alert bypasses all batching and reaches operations immediately by every configured channel |
| BR-I4 | A replacement bus assignment requires operations approval, and above a cost threshold, manager approval |
| BR-I5 | A bus returns to service only when its maintenance ticket is closed by an authorised role |
| BR-I6 | Incident records are immutable once submitted; follow-up is appended, never overwritten |
| BR-I7 | Consolidating two trips requires manager approval, must not exceed the target bus's capacity, and must notify every affected passenger before it takes effect |

### 3.9 Notifications

| # | Rule |
|---|---|
| BR-M1 | A notification is sent only to users with a legitimate relationship to the event |
| BR-M2 | Safety-critical notifications (SOS, incident, cancellation) ignore quiet hours and user mute settings |
| BR-M3 | Non-critical notifications respect quiet hours, per-category preferences and channel preferences |
| BR-M4 | The same event never produces duplicate notifications to the same recipient |
| BR-M5 | "Bus approaching" fires once per stop per trip, on geofence entry |
| BR-M6 | Delivery failure is retried with backoff and, for critical classes, escalated to an alternate channel |
| BR-M7 | Every notification sent is recorded with its recipient, channel, template and outcome |

### 3.10 Data protection

| # | Rule |
|---|---|
| BR-P1 | Minors' location and attendance data are restricted to the roles enumerated in §2.3 |
| BR-P2 | Every access to a student's personal data by a staff member is logged with the accessing identity |
| BR-P3 | Bulk export of personal data requires elevated privilege, a stated reason, and produces a high-visibility audit entry |
| BR-P4 | Retention periods are per data class and enforced by an automated purge |
| BR-P5 | A subject-access request can be fulfilled: all data about one person can be located and exported |
| BR-P6 | Photographs attached to incidents are stored outside the public web root and served only through an authorising check |

---

## 4. Edge cases and exception handling

The difference between a demo and a product is the list below. Each is a real situation that
occurs in normal operation and must have defined behaviour.

### 4.1 Connectivity and device

| Situation | Required behaviour |
|---|---|
| Driver's phone loses signal mid-route | Trip continues locally. Positions, stop arrivals and boarding counts are queued on the device with their real timestamps and synced on reconnect. The server reconciles by timestamp, not arrival order |
| Driver's phone battery dies | Trip is flagged stalled after the threshold. Operations is alerted. The driver can resume on another device by signing in; a supervisor can close the trip manually |
| Device clock is wrong | Server timestamps are authoritative for ordering. Client timestamps are recorded but marked untrusted when they deviate beyond tolerance |
| Duplicate sync after a retry | Every offline action carries a client-generated idempotency key; replays are absorbed without double-counting |
| Two devices signed in as the same driver | Only the most recently authenticated session may write trip data. The older session is notified and demoted to read-only |
| GPS accuracy degrades in an underpass | Low-accuracy points are stored but flagged and excluded from ETA calculation; the last good position is displayed with a staleness indicator |

### 4.2 Trip execution

| Situation | Required behaviour |
|---|---|
| Driver forgets to start the trip | Bus appears offline to students. Grace period, then operations alert. Trip can be started late with the actual time recorded, and the delay attributed |
| Driver forgets to end the trip | Auto-closed after scheduled arrival plus buffer, flagged for review, distinguishable in reports from a normally closed trip |
| Bus breaks down mid-route with students aboard | Driver reports incident with severity. Students aboard and students still waiting get **different** messages. Replacement is dispatched. Attendance transfers to the replacement trip |
| Bus is full and students remain at the stop | Boarding is refused at capacity. The stop is flagged "passengers left behind", operations is alerted, and those students receive an explicit message rather than silence |
| Driver skips a stop | Detected by geofence non-entry. Students at that stop are notified immediately, not after the trip ends |
| Road closed, driver deviates | Off-route detection raises an operations alert; the controller can accept the deviation (suppressing further alerts) or intervene |
| Student boards the wrong bus | Boarding scan or manual entry against a student not on the manifest prompts a confirm-and-record path. Their own route's system is told they will not board |
| Trip starts with the wrong bus | Driver selects the actual vehicle at trip start; the substitution is recorded and capacity recalculated |
| Two trips scheduled for the same bus | Prevented at schedule creation; if it occurs through data migration, the second start attempt is refused with a clear conflict message |
| Driver does not turn up | Operations reassigns from the available pool; if none, the trip is cancelled and passengers notified with alternatives |
| Trip runs on a declared holiday | Trip generation skips holidays; a manually created trip on a holiday requires override with a reason |

### 4.3 People and data

| Situation | Required behaviour |
|---|---|
| Student changes address mid-term | Route change request → approval → effective from a stated date → old and new assignments both retained in history |
| Student's pass expires mid-journey | The journey completes. Enforcement applies to the next boarding, not to stranding a child |
| Parent and student disagree about an absence | Both actions recorded with actor and timestamp; the most recent wins; both are visible |
| Guardian relationship is revoked | Access ends immediately; historical notifications already delivered are not retracted, but further access is refused |
| Two students share a phone number | Permitted. Uniqueness is on account identity, not contact detail; notification routing is per account |
| A student is also a part-time driver | Not supported by a single account. Two accounts, distinct credentials, explicitly linked in the staff record |
| Duplicate student records after an import | Import performs match-and-merge with a human review queue; never silent duplication |
| A driver leaves employment mid-term | Account deactivated; future schedule assignments flagged as unstaffed; historical trip records retain the driver reference |

### 4.4 Timetable and calendar

| Situation | Required behaviour |
|---|---|
| Term ends | Trip generation stops at the term boundary from the academic calendar |
| Unplanned closure (weather, strike) | Operations declares a service suspension for a date or window; all affected trips cancel in one action with one notification each |
| Exam period with altered timings | A named schedule variant with its own date range temporarily supersedes the standard timetable |
| Daylight-saving or timezone change | All times stored in UTC and rendered in institution-local time; schedule times are wall-clock local and do not shift |
| A schedule is edited while trips already exist for it | Existing generated trips are unaffected; the change applies to future generation. The editor is told this explicitly |

### 4.5 Concurrency

| Situation | Required behaviour |
|---|---|
| Two controllers assign the same replacement bus | Enforced at the data layer; the second attempt fails with a conflict, not a silent overwrite |
| Two boarding actions arrive simultaneously at capacity | Serialised; exactly one succeeds |
| Manager and controller edit the same schedule | Optimistic concurrency: the second save is rejected with a diff and a re-apply option |
| Trip generation runs twice | Idempotent by (schedule, date); the second run creates nothing |

### 4.6 Failure of dependencies

| Dependency | Behaviour on failure |
|---|---|
| Maps / routing provider | ETA falls back to schedule-based estimates and is labelled as such. Tracking and operations continue |
| Push notification service | Fall back to SMS for critical classes; queue and retry non-critical; surface degraded status on the operations dashboard |
| SMS gateway | Retry, then escalate to in-app and email; alert operations that a channel is down |
| Payment gateway | Payment marked pending; entitlement unchanged until confirmed; no service interruption for existing valid passes |
| Database unavailable | Read-only degradation where possible; drivers keep operating offline; explicit incident banner to staff |

---

## 5. Module dependency map

```
                        ┌──────────────────────┐
                        │  Identity & Access   │  (no dependencies)
                        └───────────┬──────────┘
                                    │  everything below requires identity
        ┌───────────────┬───────────┼──────────────┬────────────────┐
        │               │           │              │                │
   ┌────▼────┐    ┌─────▼─────┐  ┌──▼──────┐  ┌────▼──────┐   ┌─────▼──────┐
   │  Fleet  │    │  People   │  │ Network │  │  Finance  │   │   Config   │
   │ (buses, │    │(students, │  │(routes, │  │ (fees,    │   │ (calendar, │
   │ drivers)│    │ parents)  │  │  stops) │  │  passes)  │   │  settings) │
   └────┬────┘    └─────┬─────┘  └──┬──────┘  └────┬──────┘   └─────┬──────┘
        │               │           │              │                │
        │               │      ┌────▼─────┐        │                │
        │               └──────►Assignment◄────────┘                │
        │                      │(student→ │                         │
        │                      │route+stop)                         │
        │                      └────┬─────┘                         │
        │                           │                               │
   ┌────▼───────────────────────────▼───────────────────────────────▼────┐
   │                        Scheduling (timetable)                        │
   └────────────────────────────────┬─────────────────────────────────────┘
                                    │
   ┌────────────────────────────────▼─────────────────────────────────────┐
   │                      Trip Lifecycle (the day)                        │
   └──┬──────────┬──────────┬──────────┬──────────┬───────────┬───────────┘
      │          │          │          │          │           │
 ┌────▼───┐ ┌────▼────┐ ┌───▼────┐ ┌───▼─────┐ ┌──▼───────┐ ┌─▼─────────┐
 │Tracking│ │Attendance│ │  ETA  │ │Incidents│ │Consolida-│ │Notifica-  │
 │  (GPS) │ │ (+1/−1) │ │        │ │         │ │  tion    │ │  tions    │
 └────┬───┘ └────┬────┘ └───┬────┘ └───┬─────┘ └──┬───────┘ └─▲─────────┘
      │          │          │          │          │           │
      │          │          │     ┌────▼──────┐   │           │ every module
      │          │          │     │Maintenance│   │           │ emits events
      │          │          │     └────┬──────┘   │           │
      │          │          │     ┌────▼──────┐   │           │
      │          │          │     │Replacement│   │           │
      │          │          │     └───────────┘   │           │
      └──────────┴──────────┴──────────┬──────────┴───────────┘
                                       │
                          ┌────────────▼────────────┐
                          │  Reporting & Analytics  │
                          └────────────┬────────────┘
                                       │
                          ┌────────────▼────────────┐
                          │      Audit Trail        │  ← written to by ALL
                          └─────────────────────────┘
```

### 5.1 Dependency rules

- **Identity is the root.** Nothing functions without it. It has no upstream dependency.
- **Fleet, People, Network and Config are independent siblings.** They can be built and
  populated in parallel. This is the correct first construction phase.
- **Assignment is the first true join.** It needs a student, a route and a stop to exist.
- **Scheduling needs Fleet + Network.** A timetable is meaningless without buses and routes.
- **Trip Lifecycle is the spine.** It consumes Scheduling and produces the events every
  downstream module reacts to. Nothing below it can be built or tested before it exists.
- **Tracking, Attendance, ETA, Incidents and Consolidation all hang off Trip.** They are
  siblings and can be built in parallel once Trip is real.
- **Maintenance depends on Incidents. Replacement depends on Maintenance and Fleet.**
- **Notifications is a subscriber, not a dependency.** Every module publishes to it; it
  depends on Identity (for preferences and addressing) and nothing else. It must be built
  such that its failure degrades but never blocks the publishing module.
- **Reporting reads everything and writes nothing.**
- **Audit is written by everything and never modified by anything.**

### 5.2 Build sequence implied by the graph

1. Identity & Access, Config
2. Fleet, People, Network (parallel)
3. Assignment, Finance/Passes
4. Scheduling
5. **Trip Lifecycle** ← the critical path
6. Tracking, Attendance, ETA (parallel)
7. Notifications
8. Incidents → Maintenance → Replacement
9. Consolidation
10. Reporting & Analytics

Audit is not a phase; it is a cross-cutting obligation of every phase from step 1.

---

## 6. Navigation model

### 6.1 Structural principle by client

- **Admin console (web):** persistent left sidebar with module groups, top bar for global
  search / alerts / account, main content area. Deep linking is mandatory — every list,
  filter state and detail view has an addressable URL that can be pasted into a chat with a
  colleague. Breadcrumbs on every screen deeper than one level.
- **Driver app (mobile):** deliberately shallow. During an active trip the app is
  effectively single-screen with a persistent trip control surface; everything else is
  secondary. A driver must never hunt through menus at a bus stop.
- **Student and parent apps (mobile):** bottom tab bar, four tabs maximum, with the live
  tracking map as the default landing view during service hours and the schedule outside them.

### 6.2 Admin console top-level structure

```
Dashboard
Live Operations ──── Live Map · Active Trips · Alerts · Today's Schedule
Fleet ─────────────── Buses · Maintenance · Documents · Fuel
People ────────────── Students · Drivers · Parents · Staff
Network ───────────── Routes · Stops · Schedules · Service Calendar
Operations ────────── Trips · Attendance · Incidents · Replacements · Consolidation
Finance ───────────── Fee Structures · Passes · Payments · Dues
Communication ─────── Announcements · Notification Log · Templates
Reports ───────────── Operational · Fleet · Occupancy · Incident · Financial · Custom
Administration ────── Users · Roles · Settings · Integrations · Audit Log · Data
```

### 6.3 Cross-cutting navigation guarantees

- Global search reaches any entity by identifier, name, registration or phone
- Every entity referenced anywhere is a link to its detail screen
- Detail screens use tabs for related data rather than forcing navigation away
- Any list can be exported from the list itself, respecting the active filters
- Unsaved-changes protection on every form
- The alert centre is reachable from every screen without losing context
- Back always returns to the list with its filters and scroll position intact

---

## 7. Interaction matrix

Who triggers what for whom. This is the map of every human-to-human interaction the system
mediates.

| From ↓ To → | Student | Parent | Driver | Operations | Manager | Maintenance | Finance |
|---|---|---|---|---|---|---|---|
| **Student** | — | absence note | — | problem report, feedback | route-change request | — | pass renewal request |
| **Parent** | absence for child | — | — | enquiry, complaint | route-change request | — | payment |
| **Driver** | delay/arrival notices | boarding confirmations | — | incident, SOS, leave request | — | vehicle defect report | — |
| **Operations** | announcements, cancellations | announcements | duty assignment, reassignment | — | replacement approval request | ticket escalation | flags unpaid rider |
| **Manager** | policy announcements | policy announcements | roster, leave decision | approves/rejects | — | approves service cost | budget |
| **Maintenance** | — | — | vehicle-ready notice | bus availability change | — | — | parts cost |
| **Finance** | pass status, dues reminder | invoice, receipt | — | entitlement list | revenue report | — | — |
| **System** | all trip-event notices | all child-trip notices | duty reminders, stalled-trip prompt | alerts, recommendations | approval queue, digests | auto-opened tickets | expiry, dues |

---

## 8. Complete operational workflow — the day, end to end

**T−1 day, 22:00 — Generation.** The system reads the timetable and the service calendar,
skips holidays and suspensions, and creates tomorrow's trips, each bound to a schedule, a
route, a bus and a driver. Conflicts (unstaffed schedules, buses in maintenance, drivers over
duty hours, expired documents) are surfaced on the operations dashboard as an exception list
for morning review. Passenger manifests are built from current assignments minus declared
absences.

**T−1 day, 22:15 — Notice.** Drivers receive tomorrow's duty. Students and parents receive
any changes that affect them.

**06:30 — Pre-trip.** Drivers sign in, see their duty, and complete the vehicle inspection
checklist. A failed inspection item blocks the start and raises a maintenance ticket and an
operations alert immediately, while there is still time to substitute.

**06:45 — Start.** The driver starts the trip. The system verifies bus availability, driver
licence, duty hours, and inspection completion, then moves the trip to `RUNNING`, the bus to
`RUNNING` and the driver to `ON_TRIP`. Position streaming begins. Every assigned passenger and
their guardians are notified that the bus has departed.

**06:45–08:15 — Run.** Position flows every few seconds. ETAs recalculate. Geofence entry at
each stop fires "your bus is arriving" to the students waiting there and their parents. At
each stop the driver marks arrival and counts passengers on. Boarding at capacity is refused.
Each boarding notifies that student's guardians. Delay beyond threshold notifies everyone
downstream. Operations watches the whole fleet on one map.

**08:15 — Completion.** The driver ends the trip at the destination. The trip moves to
`COMPLETED`, the bus to `AVAILABLE`, the driver to `AVAILABLE`. Final attendance is frozen.
Guardians receive arrival confirmation. Any discrepancy — headcount not matching boardings,
stops skipped, passengers left behind — is flagged for review rather than quietly closed.

**Midday — Exceptions and administration.** Incidents are triaged, maintenance is scheduled,
new students are enrolled and assigned, fees are recorded, route change requests are decided,
and the consolidation engine proposes merges for the evening's low-occupancy runs.

**16:30–18:00 — Evening run.** The same cycle, outbound. Higher parental attention: the
"child got off at their stop" notification is the single most-watched event in the system.

**20:00 — Close of day.** Automated reconciliation: trips that did not close properly, trips
with attendance anomalies, unresolved alerts. Daily summary reports are produced and
distributed. Retention purges run.

**Weekly.** Utilisation and punctuality review; maintenance planning; roster planning.

**Monthly.** Cost and revenue reporting; document expiry review; consolidation effectiveness;
capacity planning against actual occupancy.

**Termly.** Timetable rebuild, route rationalisation against demand, fee cycle, mass pass
renewal, archival.
