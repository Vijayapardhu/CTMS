# Driver App — Phase 2: User Journeys

**Depends on:** [01 — API contract](01-api-contract.md) · **Feeds:** Phase 3 screen inventory
**Rule:** every branch below terminates in a state the API can actually produce. Where a journey forks, the fork is a real API response, not a guess.

---

## How to read this

Each journey states its **trigger**, its **preconditions**, the **path**, and — the part that matters — every **fork**. A journey documented only along its happy path produces an app that works in the office and fails at 06:40 in the rain.

Forks are labelled by what causes them:

| Marker | Meaning |
|---|---|
| `✕` | The API refused. There is a real status code and message |
| `⚡` | Connectivity failed. The app must continue without the server |
| `⏱` | Time or state moved underneath the driver |
| `↺` | Recoverable — the driver can retry or take another path |
| `⛔` | Terminal — the driver cannot proceed and must be told why |

---

## The spine

Everything else hangs off this. A driver who does nothing unusual walks this line once each way, twice a day.

```
  Open app
     │
     ▼
  Authenticate ─────────────────────────────────► ⛔ Session expired
     │
     ▼
  Today's trip ──── no trip ────────────────────► ⛔ Nothing assigned
     │
     ▼
  Is the bus cleared?  ◄──────────────────┐
     │                                    │
     ├─ no inspection today ──► Inspection ┤
     ├─ documents lapsed ─────────────────►⛔ Vehicle blocked
     ├─ maintenance overdue ──────────────►⛔ Vehicle blocked
     │
     ▼ cleared
  Start trip ──── refused ──────────────────────► ⛔ Show every reason
     │
     ▼
  RUNNING ◄─────────────────────────────┐
     │                                  │
     ├──► GPS stream (continuous)       │
     ├──► Stop arrival ──► Board ───────┤
     ├──► Incident ─────────────────────┤
     └──► End trip
              │
              ▼
         Trip summary
```

Note what is **not** on the spine: notifications, profile, history, settings. A driver must be able to complete an entire shift without touching any of them.

---

## J1 · Authentication

**Trigger** app launch · **Precondition** none

```
Launch
  │
  ├─ no stored token ──────────────────────► Login
  │
  ├─ stored token ──► GET /auth/me
  │                     │
  │                     ├─ 200 ────────────► Dashboard
  │                     │
  │                     ├─ 401 ──► POST /auth/refresh
  │                     │            ├─ 200 ──► retry /me ──► Dashboard
  │                     │            └─ 401 ──► ⛔ Session expired ──► Login
  │                     │
  │                     └─ ⚡ offline ─────► Dashboard (cached identity, offline banner)
  │
  └─ Login ──► POST /auth/login
                 ├─ 200 ──────────────────► Dashboard
                 ├─ ✕ 401 ────────────────► "Email or password is incorrect"
                 ├─ ✕ 422 ────────────────► field errors
                 └─ ✕ 429 ────────────────► ↺ "Too many attempts. Wait a minute."
```

**Forks that matter:**

- **`401` does not always mean "expired".** A deactivated account is rejected on *every* request, so a driver mid-shift can be logged out by an administrator. The message must not say "your session expired" when the real answer is "your account has been deactivated" — and the app cannot tell them apart. Say: *"You have been signed out. If this is unexpected, contact the transport office."*
- **Refresh exactly once.** A refresh loop against a revoked token burns the rate limit and locks the driver out for a minute.
- **⚡ Offline launch with a stored token is a valid start.** Do not block on `/auth/me`. Show the cached identity and the last known trip; reconcile when the network returns. A driver in a depot basement must still see today's assignment.
- **`429` on login is 5/min per email.** The copy must say how long to wait, because a driver who taps repeatedly will extend the lockout.

**Exit states:** Dashboard · Login · Session expired

---

## J2 · Start of shift — today's trip

**Trigger** Dashboard opens · **Precondition** authenticated

```
GET /trips?date=today            (driver-scoped server-side)
  │
  ├─ empty ──────────────────────► ⛔ "No trip assigned today"
  │                                   └─ offer: view schedule, contact office
  │
  ├─ trip SCHEDULED ─────────────► Readiness check (J3)
  ├─ trip RUNNING ───────────────► resume running trip (J7) — never re-start
  ├─ trip COMPLETED ─────────────► Trip summary (J13)
  └─ trip CANCELLED ─────────────► Cancellation notice + reason
                                     └─ merged_into_trip_id present?
                                          └─► "Your service was combined.
                                               Look for {registration}."
```

**Forks that matter:**

- **A `RUNNING` trip on launch means the app was killed mid-shift.** Resume directly into the running state and restart the GPS stream. Do not show a start button — `POST /start` will return 409 and the driver will think the app is broken.
- **A `CANCELLED` trip may carry `merged_into_trip_id`.** That is a consolidation (FR-13): the driver's service was merged into another. Show which bus, not just "cancelled".
- **⚡ Offline:** serve the cached trip. Everything read-only still works; the readiness check below is the first thing that genuinely needs the server.

**Exit states:** Readiness · Running · Summary · Empty

---

## J3 · Readiness check

**Trigger** trip is `SCHEDULED` · **Precondition** trip has a bus

```
GET /buses/{id}/service-readiness
  │
  ├─ cleared: true ──────────────────────────► Start trip available (J6)
  │
  └─ cleared: false ──► render reasons[] verbatim
        │
        ├─ "No pre-trip inspection has been completed today."
        │        └─► ↺ Inspection (J4)  ← the only self-serve fix
        │
        ├─ "{Document} is missing or expired."
        │        └─► ⛔ Vehicle blocked — operations only
        │
        ├─ "The bus is {STATUS}."
        │        └─► ⛔ Vehicle blocked
        │
        └─ "Today's inspection failed on: {items}."
                 └─► ⛔ Vehicle blocked, maintenance ticket already open
```

**The design decision this journey forces:** the reasons list is **already written for the driver** by the backend. The app renders it; it does not re-word it. But only *one* of those reasons is something the driver can act on. So the screen must separate **"you can fix this"** from **"somebody else must fix this"** — otherwise a driver stares at a blocked bus tapping a button that will never work.

- Actionable → primary button, "Start inspection"
- Not actionable → informational, with "Call transport office" as the only action

**⚡ Offline:** readiness cannot be determined offline. Show the last known result with its timestamp and a clear caveat. **Never allow a start attempt to be queued** — the safety gate must evaluate against live state.

**Exit states:** Start available · Inspection · Vehicle blocked

---

## J4 · Pre-trip inspection

**Trigger** driver taps "Start inspection" · **Precondition** trip scheduled, bus assigned

```
GET /inspections/checklist          (server-driven — never hard-coded)
  │
  ▼
Enter odometer ──► ✕ below recorded total ──► ↺ "Must be at least {n} km"
  │
  ▼
For each item:  Pass ──────────────────────► next item
                  │
                  └─ Fail ──► notes (required)
                              │
                              ├─ safety-critical? ──► Evidence required (J5)
                              └─ not critical ─────► next item
  │
  ▼
Review ──► show the consequence BEFORE submitting:
             "This will take the bus out of service and open a
              maintenance ticket."
  │
  ▼
POST /buses/{id}/inspections
  │
  ├─ 201 outcome=PASSED ───────────────► ✓ Cleared → Start trip
  ├─ 201 outcome=PASSED_WITH_DEFECTS ──► ✓ Ticket opened, bus may still run
  ├─ 201 outcome=FAILED ───────────────► ⛔ Bus out of service (J14)
  │
  ├─ ✕ 422 items.*.evidence_id ────────► ↺ "Photograph required for {item}"
  ├─ ✕ 422 incomplete checklist ───────► ↺ jump to the first unanswered item
  └─ ✕ 409 odometer ───────────────────► ↺ correct the reading
```

**Forks that matter:**

- **The outcome is decided server-side.** The app must never predict it. A driver told "this will fail" who then sees `PASSED_WITH_DEFECTS` will stop trusting the app.
- **Show the consequence before submission, not after.** A driver who fails a brake check needs to know the bus is about to be grounded *while they can still call the depot*, not once it has happened.
- **⚡ Offline: an inspection cannot be submitted.** It requires an evidence upload for critical failures, and evidence cannot be uploaded offline. The correct behaviour is to **preserve the draft locally** and tell the driver plainly: *"Saved. This inspection will submit when you have signal."* Do not silently queue it as though it succeeded — the bus is not cleared until the server says so.
- **Discarding a part-filled checklist requires confirmation.** Fourteen items entered on a phone in the cold is real work.

**Exit states:** Cleared · Passed with defects · Failed → vehicle blocked · Draft saved offline

---

## J5 · Evidence capture

**Trigger** a failed safety-critical inspection item, or an operational incident · **Precondition** camera permission

```
Trigger ──► permission granted? ──── no ──► ⛔ Camera permission (J17)
  │
  ▼ yes
Capture ──► Preview ──┬─ Retake ──► Capture
                      └─ Use
                          │
                          ▼
                    POST /evidence  (multipart: file, category)
                          │
                          ├─ 201 ──────────► hold id, cite on submit
                          │
                          ├─ ✕ 409 mime ───► ⛔ "Photographs only"
                          ├─ ✕ 409 size ───► ↺ "Too large" — recapture lower
                          └─ ⚡ offline ────► queue upload, block submission
```

**Forks that matter:**

- **Upload late, not early.** An uploaded file that is never attached is **swept after 48 hours**. Upload when the driver confirms the photo, not when the camera opens.
- **⚡ Offline is the hard case.** The photo can be captured and stored locally, but the id it will receive does not exist yet — and the incident or inspection cannot cite an id that does not exist. So the *whole submission* queues together: photo bytes plus the report, uploaded as a pair when signal returns. This is the one place where the offline queue holds a multi-step transaction rather than a single call.
- **Category is not a user choice.** It is determined by where the driver came from — `INSPECTION_PHOTO` or `INCIDENT_PHOTO`. Never show a picker. Citing the wrong category is a 409 the driver cannot understand.
- **One photo, one record.** Re-using an id is a 409. The app should make re-use impossible by discarding the id once cited.

**Exit states:** Evidence attached · Queued · Refused

---

## J6 · Start trip

**Trigger** driver taps "Start trip" · **Precondition** readiness cleared

```
Confirm ──► POST /trips/{id}/start
  │
  ├─ 200 ────────────────────────────► RUNNING (J7) — start GPS immediately
  │
  ├─ ✕ 409 reasons[] ────────────────► ⛔ show every reason (not just the first)
  │     ├─ not cleared for service ──► back to J3
  │     ├─ outside start window ─────► ⏱ "Cannot start until {time}"
  │     ├─ licence expired ──────────► ⛔ operations only
  │     ├─ already on an active trip ► resume that trip instead
  │     ├─ stood down after incident ► ⛔ "Pending review" (BR-109)
  │     └─ maintenance overdue ──────► ⛔ "{service} past its grace period"
  │
  └─ ⚡ offline ─────────────────────► ⛔ cannot start offline (see J3)
```

**Forks that matter:**

- **`reasons[]` is a list, and every entry matters.** The backend deliberately reports all blocking reasons at once rather than the first, so a driver fixes one thing and discovers the next. Render the whole array.
- **"Outside the start window" is the only *time*-based refusal.** It resolves itself. Show a countdown, not an error — and re-enable the button automatically.
- **"Already on an active trip"** means the driver has a running trip elsewhere. Offer to jump to it, don't just refuse.
- **Confirmation is required but must be one tap.** A driver at the depot gate should not face a dialog with two paragraphs.

**Exit states:** Running · Blocked · Waiting for window

---

## J7 · Trip running

**Trigger** trip becomes `RUNNING` · **Precondition** location permission granted

This is where the driver spends ninety minutes. Everything here is background behaviour plus glanceable state.

```
RUNNING
  │
  ├── GPS stream (5–10s)  ──► POST /trips/{id}/positions
  │      │
  │      ├─ 200 ────────────────────► ✓ live
  │      ├─ ✕ 422 outside area ────► ⏱ buffer, badge "outside service area"
  │      ├─ ✕ 409 implausible ─────► discard silently (server is right)
  │      ├─ ✕ 409 not running ─────► ⛔ stop the stream, re-fetch trip
  │      ├─ ✕ 429 ─────────────────► back off; never shown to the driver
  │      └─ ⚡ offline ─────────────► buffer with idempotency keys (J15)
  │
  ├── Live state (10s)   ──► GET /trips/{id}/live
  │      └─ is_stale ──────────────► ⏱ show the stale badge, not a fresh marker
  │
  ├── Stop arrival ─────────────────► J8
  ├── Incident ─────────────────────► J10 / J11 / J12
  ├── Replacement dispatched ───────► J12 (push-driven)
  └── End trip ─────────────────────► J13
```

**Forks that matter:**

- **GPS loss is not an error state.** It is normal — tunnels, basements, hills. The app buffers and shows a quiet indicator. It must never interrupt the driver with a dialog, and must never stop the trip.
- **`is_stale` comes from the server** (position older than 120s). Render it as a distinct visual state. This is the difference between "the bus is here" and "the bus was here two minutes ago", and a parent's app is showing the same thing.
- **A `409 not running`** means the trip was cancelled or auto-closed underneath the driver — usually a consolidation or an operations action. Stop the stream, re-fetch, and explain what happened. Never keep posting into a closed trip.
- **⚡ Offline running is the normal case, not the exception.** Every driver-facing action during a trip must work with no network: boarding, arrival, skip, SOS, incident. Only the map tiles and ETA degrade.

**Exit states:** Completed · Cancelled · Incident flow

---

## J8 · Stop arrival

**Trigger** geofence entry (server-detected) or driver action

```
Approaching ──► server fires geofence ──► push "Approaching {stop}"
  │
  ▼
Arrived
  ├─ automatic (geofence confirmed) ──► stop screen opens
  └─ ⏱ geofence missed ──────────────► ↺ driver taps "I'm here"
                                          POST /stops/{stopId}/arrive
  │
  ▼
GET /stops/{stopId}/manifest  ──► who is expected here
  │
  ├─ Board students (J9)
  ├─ Record left behind ──► POST /left-behind  (student_ids required)
  └─ Skip this stop ──────► POST /stops/{stopId}/skip
                              └─ reason required, min 5 chars
                                 └─ ✕ 422 if too short ──► ↺
  │
  ▼
Depart ──► next stop
```

**Forks that matter:**

- **Manual arrival must always be available.** Geofences miss — poor GPS, a stop moved, a driver parked fifty metres away. A driver who cannot mark arrival cannot board anyone.
- **Skipping tells the waiting students.** The API says so: *"Stop marked as skipped. The students waiting there have been told."* Surface that consequence in the confirmation, because a driver skipping a stop is deciding to leave people behind and should know the message goes out.
- **"Left behind" is a safety record, not an error.** It needs named students, so it is only reachable from the manifest.
- **⚡ Offline:** arrival, skip and left-behind all queue. The manifest must be cached at trip start — a driver cannot board people from a list they cannot load.

**Exit states:** Boarded · Skipped · Departed

---

## J9 · Boarding

**Trigger** at a stop · **Precondition** trip running

This journey is used more than any other, usually while standing, often in under two seconds per passenger.

```
      ┌──────────────┬──────────────┐
      │   Anonymous  │    Named     │
      │   headcount  │   student    │
      └──────┬───────┴──────┬───────┘
             │              │
    POST /board          POST /board { student_id }
    (no body)                │
             │              │
             ├─ 200 ────────┴────► counter increments
             │
             ├─ ✕ 409 full ──────► ⛔ "The bus is full ({n}/{capacity})"
             ├─ ✕ 409 below zero ► ⛔ (alight only)
             └─ ⚡ offline ───────► optimistic increment + queue
```

**Forks that matter:**

- **Anonymous is the default.** `student_id` is optional on the API precisely so the common case is one tap. Named boarding is a secondary path reached from the manifest.
- **Optimistic UI, reconciled later.** Offline, the counter increments immediately and queues. When it syncs, the server number wins — and if they differ, that difference becomes an **attendance discrepancy** (BR-266) which operations reviews. The app does not need to resolve it; it needs to not hide it.
- **Capacity refusal is a hard stop with a real number.** The message already contains the counts. Show it.
- **Alight is the mirror**, and must be equally reachable — a driver dropping students at a stop is doing the same job in reverse.

**Exit states:** Counted · Refused · Queued

---

## J10 · SOS

**Trigger** driver holds the SOS control · **Precondition** none — this must work in every state

This is the journey that justifies the whole app's architecture.

```
Hold SOS (1.5s, haptic) ──► confirm sheet
  │                            └─ cancel: instant, no friction
  ▼
POST /incidents { incident_type: "SOS", trip_id? }
  │           ← nothing else. No description. No photograph.
  │
  ├─ 201 ───────────────► SOS ACTIVE
  │                        └─ "Operations has been alerted"
  │
  └─ ⚡ offline / failure ► SOS QUEUED  ← never "failed"
        │
        ├─ persist locally BEFORE the network attempt
        ├─ retry with backoff, across app restarts
        ├─ offer: 📞 call transport office  (device dialler)
        └─ offer: ✉ send SMS with coordinates (store-and-forward)
  │
  ▼
SOS ACTIVE ──► add note ──► POST /incidents/{id}/notes
           └─► false alarm ──► POST /incidents/{id}/cancel { note }
                                 └─ recorded, never erased (BR-355)
```

**Forks that matter:**

- **Persist before the network.** The process can be killed mid-request. An SOS that exists only inside a pending HTTP call is an SOS that never happened.
- **Never show "failed".** Show *queued*, with the fallbacks. Telling someone in an emergency that their alert failed, with no alternative, is the worst possible outcome.
- **The device fallbacks are the point.** Cellular voice and SMS survive conditions the data network does not. This is BR-354's client half, and it is not optional.
- **Cancelling requires a note and keeps the record.** A false alarm is still a fact about what happened. The UI must not imply it is being deleted.
- **A hold, not a tap** — 1.5s with haptic feedback. Long enough to prevent a pocket press, short enough to be instant in a real emergency. And a pocket-press that *does* get through is fine: cancelling is easy and recorded.

**Exit states:** Active · Queued · Cancelled

---

## J11 · Breakdown and operational incidents

**Trigger** driver reports a vehicle fault · **Precondition** trip running

```
Select type (from GET /incidents/types — server-driven)
  │      types where requires_photo = true
  ▼
Description (required) ──► ✕ 422 if blank
  │
  ▼
Evidence (required) ──► J5
  │
  ▼
"Can the vehicle continue?"  ← this single toggle changes everything
  │
  ├─ no  ──► bus taken out of service + replacement search begins
  └─ yes ──► bus stays in service, ticket still opens
  │
  ▼
POST /incidents
  │
  ├─ 201 ──► Incident submitted
  │            ├─ maintenance ticket opened automatically
  │            └─ if vehicle_can_continue = false → J12
  │
  ├─ ✕ 422 evidence_id ──► ↺ photograph required
  └─ ⚡ offline ──────────► queue report + photo together (J5)
```

**Forks that matter:**

- **`vehicle_can_continue` is the highest-consequence control in the app.** False triggers a replacement search and grounds the bus. It must be an explicit choice with both options equally weighted — never a default, never a checkbox the driver can miss.
- **The type catalogue is server-driven** and carries `requires_photo` and `class` per type. Do not hard-code which types need a photograph.

**Exit states:** Submitted · Replacement pending · Queued

---

## J12 · Replacement bus

**Trigger** operations dispatches a replacement · **Precondition** an incident grounded the vehicle

```
Incident (vehicle cannot continue)
  │
  ▼
System recommends ──► operations approves ──► operations notifies ──► dispatched
  │                                                                     │
  │  the driver sees none of these — they are not theirs to decide      │
  ▼                                                                     ▼
Replacement status card appears                              push: "Replacement on the way"
  │                                                           carries {registration}
  ▼
Arrived ──► passengers transfer ──► the TRIP MOVES to the new bus
                                      │
                                      └─ same trip, same attendance,
                                         different vehicle
```

**Forks that matter:**

- **The driver has no controls here.** Approve, reject, dispatch and arrival are all operations actions (403 for a driver). The app shows status only. Rendering a disabled "Approve" button would be worse than showing nothing.
- **The trip identity survives the vehicle change.** Attendance and history follow the trip, not the bus. If the driver also changes, the original is preserved (BR-258).
- **Show the registration number.** It is the only thing that makes the replacement recognisable at the roadside.

**Exit states:** Awaiting · Dispatched · Arrived

---

## J13 · End of trip

**Trigger** driver taps "End trip" · **Precondition** trip running

```
Confirm ──► odometer reading ──► ✕ 409 if below recorded ──► ↺
  │
  ▼
POST /trips/{id}/complete
  │
  ├─ 200 ──► Trip summary
  │            ├─ distance, duration, stops served
  │            ├─ passengers carried
  │            └─ incidents raised
  │
  ├─ ✕ 409 not running ──► ⏱ already closed (auto-close or operations)
  └─ ⚡ offline ──────────► queue; show "will close when you have signal"
```

**Forks that matter:**

- **⏱ A trip can be auto-closed** by the scheduler if it ran past its arrival buffer. A driver ending a trip that has already closed should see "This trip was already closed at {time}", not an error.
- **The summary is the driver's receipt.** It is also where an attendance mismatch first becomes visible — but the driver does not resolve it. Reconciliation runs overnight and operations reviews it.
- **Offline completion queues** but the trip is not closed until the server says so. Do not show a completed state the server does not agree with.

**Exit states:** Completed · Already closed · Queued

---

## J14 · Vehicle blocked

**Trigger** readiness fails, or an inspection fails · **Precondition** none

```
Blocked
  ├─ why: reasons[] from the API, verbatim
  ├─ what happens next: maintenance ticket reference if one opened
  ├─ what the driver can do: call the transport office
  └─ what the driver cannot do: clear it themselves
       (signing off maintenance is OPERATIONS — 403)
```

This is a **dead-end screen by design**. Its only job is to explain clearly and give one route out — contact. A dead end that explains itself is fine; a dead end that looks like a bug is not.

---

## J15 · Connectivity loss

**Trigger** network unavailable · **Precondition** any

Not a screen. A **cross-cutting mode** every journey must handle.

```
Online ──► ⚡ lost ──► OFFLINE MODE
                        │
                        ├─ banner (persistent, not a toast)
                        ├─ reads: served from cache with age
                        ├─ writes: queued with idempotency keys
                        ├─ GPS: buffered continuously
                        └─ SOS: fallbacks offered immediately
                        │
                        ▼
                   ⚡ restored ──► SYNCING
                        │            ├─ progress, count remaining
                        │            └─ throttled replay (60/min ceiling)
                        ▼
                    ├─ all accepted ──► Online
                    └─ some rejected ──► ⚠ Sync conflicts (J16)
```

**What must work fully offline:** boarding, alighting, stop arrival, skip, left-behind, SOS, incident capture, GPS buffering, reading today's trip and manifest.

**What cannot work offline:** starting a trip (the safety gate needs live state), submitting an inspection (evidence must upload first), readiness checks.

That division is not a limitation to work around — it is the safety model. Anything that decides *whether a bus may carry children* evaluates server-side, live.

---

## J16 · Sync conflicts

**Trigger** a queued action is rejected on replay

```
Replay ──► rejected
  ├─ 409 duplicate (idempotency) ──► silent, absorbed. Not a conflict.
  ├─ 409 capacity ────────────────► ⚠ "3 boardings could not be applied"
  ├─ 409 trip closed ─────────────► ⚠ "The trip closed before this synced"
  └─ 422 outside service area ────► ⚠ positions discarded, count shown
```

**The rule:** a rejected queued action is **never silently dropped**. It surfaces in the offline queue screen with what it was and why it failed. A driver who counted twenty-three passengers and finds nineteen recorded, with no explanation, has lost trust in the app permanently.

Duplicates absorbed by an idempotency key are **not** conflicts and must not be reported — that is the mechanism working.

---

## J17 · Permissions

**Trigger** first use of a capability · **Precondition** none

```
Location ──► required for GPS
   ├─ granted ────────────► proceed
   ├─ "while in use" ─────► ⚠ background tracking degraded — explain
   └─ denied ─────────────► ⛔ cannot run a trip. Explain why, offer settings

Camera ───► required for evidence
   └─ denied ─────────────► ⛔ cannot complete a failing inspection

Notifications ──► required for dispatch and approach alerts
   └─ denied ─────────────► ⚠ degraded, app still works
```

Each permission is requested **at the moment it is first needed**, with a plain sentence about why — never all at launch. A driver denied location cannot run a trip, and the app must say that in those words rather than showing a broken map.

---

## Journey → screen implications

What the journeys already tell us, before any screen is drawn:

| Observation from journeys | Implication for Phase 3 |
|---|---|
| The trip has 4 distinct shapes (none / scheduled / running / closed) | Trip is **one destination that changes state**, not four screens |
| `reasons[]` splits into actionable vs not | Blocked needs **two visual treatments**, one screen |
| SOS must be reachable from every running state | SOS is **persistent chrome**, not a destination |
| Boarding is used hundreds of times per shift | Boarding is a **full screen**, not a sheet |
| Replacement has no driver controls | Replacement is a **status card**, not a screen |
| Offline is a mode, not a page | Offline needs **banner + queue screen**, not per-screen variants |
| Evidence is always reached from a parent flow | Evidence is a **modal sub-flow**, never a tab |

---

**Next document:** Phase 3 — Screen Inventory, derived from these journeys. Nothing appears in the inventory that no journey reaches.
