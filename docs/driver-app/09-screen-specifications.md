# Driver App — Phase 9: Screen Specifications

**Derived from:** Phases 3–8. Each screen states the **Frontend Contract**: endpoint → component → state → loading → error → offline → empty → retry.

---

## Contract format

Every screen below carries this block. It is the implementation checklist.

```
API        which endpoints, when called
State      which Bloc, which states
Loading    what renders while waiting
Empty      what renders with no data
Offline    what renders and what still works
Error      per status code
Retry      how the driver recovers
```

---

## P2 · Login

**Access** unauthenticated · **Entry** Splash, logout, session expiry · **Exit** Dashboard

**Layout** — logo, email, password, primary action, forgot-password link. Vertically centred, single column, no scroll at default text scale.

```
API        POST /auth/login {email, password}
State      SessionBloc: unauthenticated → authenticating → authenticated
Loading    Button shows spinner, width unchanged; fields disabled
Empty      n/a
Offline    Button disabled + banner "No connection. Sign-in needs a network."
Error      401 → "Email or password is incorrect" (field-level on neither —
                 never reveal which was wrong)
           422 → map errors.{field} onto inputs
           429 → "Too many attempts. Try again in a minute." + 60s countdown,
                 button disabled for the duration
           500 → generic + Retry
Retry      Automatic on 5xx (once). Manual otherwise.
```

**Validation** — email format client-side only as a hint; the server is authoritative. Password field never validates format on login (only on change), because telling an attacker the rules is pointless and telling a driver their existing password is "invalid" is confusing.

**A11y** — labelled fields, `TextInputAction.next` → `done`, autofill hints (`username`, `password`), error text announced via live region.

---

## R1 · Trip (root)

**Access** authenticated · **Entry** default tab · **Exit** — (root)

One destination, six states. See M1.

```
API        GET /auth/me                       (on session, cached)
           GET /trips?date=today              (on open, on resume, pull-to-refresh)
           GET /buses/{busId}/service-readiness  (when trip is SCHEDULED)
           GET /notifications/unread-count    (on open, on push)
State      TripBloc: loading | none | blocked | ready | waiting | running | closed
Loading    SkeletonLoader(card) — layout is known, so skeleton not spinner
Empty      EmptyState "No trip assigned today" + Call the office. Neutral tone.
Offline    Cached trip rendered with banner. Readiness shows last known result
           with its timestamp and an explicit caveat. Start is DISABLED offline.
Error      401 → session machine handles
           403 → should be impossible (trips are driver-scoped); log and show
                 generic
           500 → error card with Retry, cached trip still shown beneath
Retry      Pull-to-refresh always available. Auto-refresh on app resume.
```

**Per-state layout**

| State | Top | Middle | Bottom |
|---|---|---|---|
| `none` | greeting | `EmptyState` | Call office |
| `blocked` | `TripCard(blocked)` | `ReasonList` grouped | Start inspection (if actionable) + Call |
| `ready` | `TripCard(ready)` | departure, stop count, first stop | **START TRIP** (64dp) |
| `waiting` | `TripCard(ready)` | countdown to window | Start (disabled + countdown) |
| `running` | `GpsStatusPill` | `BigNumberDisplay`, `StopCard(next)`, counters | Report a problem |
| `closed` | `TripCard(closed)` | summary figures | View summary |

**Business rules surfaced**
- `blocked` reasons split by `actionable` — only the missing-inspection reason is actionable (Phase 2, J3)
- `waiting` is time-based and self-resolving; timer re-enables the button
- `running` never shows Start. If the API says RUNNING on launch, resume and restart GPS.

**Performance** — `/live` polls every 10s **only while this tab is visible and the trip is running**. Backgrounded, polling stops; GPS posting continues via the foreground service.

**Analytics** — `trip_viewed{state}`, `trip_start_tapped`, `trip_start_refused{reason_count}`.

---

## P9 · Inspection checklist

**Access** driver, trip SCHEDULED · **Entry** R1 blocked → Start inspection · **Exit** P10, or discard

```
API        GET /inspections/checklist         (on open; cache 24h)
State      InspectionBloc: loadingChecklist | editing | capturing | reviewing
Loading    SkeletonLoader(list, count: 14)
Empty      impossible — a checklist with no items is a server fault → error card
Offline    Cached checklist used. Draft edits saved locally. Evidence capture
           allowed; upload queues. Review is reachable; SUBMIT is not.
Error      500 on checklist → cannot proceed; explain and offer Retry
Retry      Manual
```

**Interaction**
- Odometer first, with the minimum stated **before** any error (`Must be at least 45 108 km`)
- Each item is `ChecklistItemTile` with `DualActionSelector`, no default
- Fail expands: notes (required, min 5 chars) and, if safety-critical, `EvidenceCard`
- Title shows `n/14`; Review disabled until every item answered, label carries the remaining count
- Back → `D1 Discard inspection?` if any item answered

**Validation (client, mirroring server)**

| Field | Rule | Message |
|---|---|---|
| odometer | ≥ bus current | "Must be at least {n} km" |
| notes on fail | required, 5–500 | "Describe what you found" |
| evidence on critical fail | required | "A photograph is required for {item}" |
| completeness | all items | Review disabled, count shown |

**Draft persistence** — written to local storage on every change. Survives kill, restart, battery death.

---

## P10 · Inspection review

```
API        POST /buses/{busId}/inspections    (on submit)
State      InspectionBloc: reviewing | submitting | submitted | rejected | queued
Loading    Submit button spinner; screen non-interactive; NOT cancellable
Offline    Submit replaced by "Save — will submit when you have signal".
           Bus is NOT cleared. Say so explicitly.
Error      422 items.*.evidence_id → return to P9, scroll to that item, highlight
           422 incomplete         → return to P9, scroll to first unanswered
           409 odometer           → return to P9, focus odometer
           500                    → stay, Retry, draft preserved
Retry      Manual; draft never lost
```

**Above the fold when any safety-critical item failed** — `ConsequencePanel(danger)`:
> This will take the bus out of service. A maintenance ticket will be opened. You will not be able to start this trip.

**The outcome is server-decided.** Render `data.outcome` from the 201. Never predict it.

| Outcome | Next |
|---|---|
| `PASSED` | P11 success → R1 `ready` |
| `PASSED_WITH_DEFECTS` | P11 with a note that a ticket opened; bus may still run |
| `FAILED` | P11 → P6 Vehicle blocked |

---

## M1/M2 · Evidence capture and preview

```
API        GET  /evidence/categories   (cached at login)
           POST /evidence              (multipart: file, category)
State      EvidenceBloc: idle | capturing | previewing | uploading | uploaded
                       | rejected | queued | blocked(permission)
Loading    Upload progress, cancellable
Offline    Photo stored locally; upload queues WITH its parent report
Error      409 mime  → "Photographs only (JPEG, PNG, HEIC, WebP)" + Retake
           409 size  → "Too large. Maximum {n} MB." + Retake at lower quality
           422       → generic + Retake
Retry      Retake, or retry upload from the queue
```

**Rules**
- Category is **derived from the caller**, never chosen by the driver
- Capture at ≤ 1920px long edge, JPEG q80 — a 12MP original is 6MB over a rural connection
- Upload on **confirm**, not on capture (48h orphan sweep)
- Discard the id once cited; re-use is a 409

**Permission** — camera denied → `E4`, stating plainly that a failing inspection cannot be completed without it.

---

## R1 · Running trip (detail)

```
API        POST /trips/{id}/positions   every 5–10s, foreground service
           GET  /trips/{id}/live        every 10s while visible
           POST /trips/{id}/board|alight  on tap
State      TripBloc(running) + GpsStreamBloc + BoardingCubit
Loading    Never blocks. First /live shows skeleton on the stop card only
Offline    Everything works. Banner + pending counts. ETA labelled estimated.
Error      positions 422 outside area → badge, keep buffering
           positions 409 implausible  → drop silently
           positions 409 not running  → STOP stream, refetch trip, explain
           positions 429              → back off 60s, invisible to driver
           board 409 full             → "The bus is full ({n}/{capacity})"
           live 5xx                   → keep last good state + stale badge
Retry      Automatic with backoff for all of it
```

**The counter is the screen.** `BigNumberDisplay` at `display` scale with tabular figures. Two `CounterButton`s at 96dp. Everything else is secondary.

**Stale handling** — when `live.position.is_stale`, the GPS pill goes `neutral`, the map marker desaturates to `staleAccent`, and the stop card shows "Position {n} min old". Three signals, because this is the one piece of data a driver must not misread.

---

## P13 · Stop details

```
API        GET  /trips/{id}/stops/{stopId}/manifest   (prefetch all at trip start)
           POST /trips/{id}/stops/{stopId}/arrive
           POST /trips/{id}/stops/{stopId}/skip
           POST /trips/{id}/left-behind
State      StopCubit: loading | pending | arrived | departed | skipped
Loading    Skeleton list
Empty      "No students expected here" — neutral, boarding still available
Offline    Manifest from prefetch cache. Arrive/skip/left-behind all queue.
Error      skip 422 reason → inline "Give a reason (at least 5 characters)"
           left-behind 422 → highlight the selection
Retry      Queued automatically
```

**Manifest is prefetched for every stop at trip start.** A driver cannot board from a list that will not load, and the stop where signal fails is the stop where they need it.

**Skip** → `S4` with `ConsequencePanel(warning)`: *"The students waiting there will be told."* That sentence comes from the API's own response message.

---

## C1 + S1 + P17 · SOS

**The most important flow in the app.**

```
API        POST /incidents {incident_type: "SOS", trip_id?, reported_at,
                            idempotency_key}
State      SosBloc (app-scoped, outlives its screen):
           idle | confirming | persistedLocal | sending | queued | retrying
           | active | cancelled
Loading    "Alerting…" — never a blocking spinner
Offline    persistedLocal → queued. Fallbacks shown IMMEDIATELY.
Error      NONE SURFACED. Any failure → queued + retry + fallbacks.
Retry      Exponential backoff, across app restarts, until acknowledged
```

**Sequence**
1. Hold `C1` 1.5s → haptic at start, mid, completion
2. `S1` confirm sheet — one decision, cancel is free
3. **Persist locally** — before any network call
4. POST with `idempotency_key` (one per press, never per attempt) and `reported_at` from the device clock
5. → `P17`

**P17 states**

| State | Headline | Actions |
|---|---|---|
| `active` | "Operations alerted at 08:31" | Call office · Add note · Withdraw |
| `queued` | **"Alert saved."** "No signal — will send automatically. Retrying…" | 📞 Call · ✉ SMS with coordinates · Withdraw |

**Never the word "failed".** Never a red error state. The fallbacks are the recovery.

**Withdraw** → `D2`, note required, `POST /incidents/{id}/cancel`. Copy must make clear it is **recorded, not erased** (BR-355).

**A11y** — `HoldToActivate` is unusable with TalkBack. Screen-reader users get a two-tap confirm button with the same visual weight.

---

## P18 · Incident report

```
API        GET  /incidents/types    (cached at login)
           POST /evidence           (when the type requires a photo)
           POST /incidents
State      IncidentBloc: composing | uploadingEvidence | submitting | submitted
                       | rejected | queued
Loading    Submit spinner
Offline    Report + photo queue as ONE unit
Error      422 evidence_id  → "A photograph is required for {type}"
           422 description  → inline
           409              → show the message verbatim
Retry      Queued; visible in M3
```

**Type picker (`S6`)** is built from `/incidents/types`, which carries `requires_photo` and `class` per type. The form reconfigures from the response — **never hard-code which types need a photo**.

**`vehicle_can_continue`** — `DualActionSelector`, no default, with `ConsequencePanel` under it: *"Choosing No takes the bus out of service and starts a replacement search."* This is the highest-consequence control in the app.

---

## S3 · End trip

```
API        POST /trips/{id}/complete {odometer_reading}
State      TripBloc: running → completing → closed
Offline    Queues; "will close when you have signal". Trip stays running locally.
Error      409 not running → D4 "This trip was already closed at {time}" → P7
           409 odometer    → inline, focus field
Retry      Automatic when online
```

**Before confirming**, show what will be recorded: stops served, passengers carried, incidents raised. It is the driver's receipt and their last chance to notice something wrong.

---

## P7 · Trip summary

```
API        GET /trips/{id}
State      loading | ready
Loading    Skeleton
Offline    Cached
Error      404 → EmptyState (not an error screen)
Retry      Pull-to-refresh
```

Distance, duration, stops served/skipped, passengers, incidents. If headcount and boarding events differ, show both — the backend raises the discrepancy (BR-266); the app's job is not to hide it. The driver **cannot** correct it (403), so offer no control that implies they can.

---

## R3 · Alerts

```
API        GET   /notifications?per_page=25    (paginated, infinite scroll)
           GET   /announcements
           PATCH /notifications/{id}/read
           POST  /notifications/read-all
State      NotificationsBloc: loading | ready | loadingMore | empty | error
Loading    Skeleton list ×5
Empty      "Nothing new" — neutral
Offline    Cached page 1; mark-read queues
Error      500 → error card + Retry, cached list beneath
Retry      Pull-to-refresh
```

Tap routes by `data.*` payload (Phase 1 deep-link table), never by parsing body text. A target that no longer exists or is forbidden lands on Alerts with a snackbar — never a raw 403/404 from a notification tap.

Critical notifications (`priority: CRITICAL`) render with `critical` treatment and are **not** dismissible by swipe.

---

## R4 · Me

```
API        GET   /auth/me
           GET   /drivers/{id}                (own only)
           PATCH /drivers/{id}/status         (own only)
           GET   /maintenance-tickets?bus_id= (own bus only)
           GET   /notification-preferences
State      ProfileBloc: loading | ready | error
Offline    Cached; duty-status change queues
Error      403 → impossible for own records; log
```

Sections: identity · duty status (`S7`) · assigned bus and why it may be off the road · notification preferences (safety categories rendered **locked with a reason**, BR-404) · offline queue → `M3` · help · about · logout (`D5`).

**Logout with a non-empty queue** must warn and refuse until synced or explicitly discarded.

---

## P6 · Vehicle blocked

```
API        GET /buses/{id}/service-readiness  (refresh on pull)
State      loading | blocked | cleared(→pop)
Offline    Last known result + timestamp + caveat
```

A deliberate dead end. `ReasonList` grouped by actionability; one route out — Call the office. If a refresh returns `cleared: true`, pop automatically back to R1.

---

## M3 · Offline queue

```
API        replays whatever is queued, FIFO per trip
State      SyncQueueBloc: empty | pending | syncing | partial
Empty      "Everything is up to date" — positive tone
Error      per-item; each failure shows the server's message verbatim
Retry      "Retry now"; automatic on connectivity restore
```

Failed items show **what** and **why**. Idempotency-absorbed duplicates are not failures and are never listed.

---

## Error screens

| Screen | Trigger | Content | Recovery |
|---|---|---|---|
| `P4` Session expired | 401 after refresh fails | "You have been signed out. If unexpected, contact the office." | → Login |
| `E2` GPS disabled | location services off | Cannot run a trip without it | Open settings |
| `E6` Forbidden | 403 | "You do not have permission." No retry. | Back |
| `E8` Server error | 500 | Generic. No internals (BR-511). | Retry |
| `E9` Maintenance | 503 | "The system is being updated." | Auto-poll 30s |

---

## Cross-cutting requirements

**Every screen**
- Pull-to-refresh where data is remote
- Offline banner when disconnected
- Text scale to 1.5× without clipping
- All targets ≥ 48dp
- Back behaviour per Phase 1

**Never**
- A spinner with no context
- A disabled control with no visible reason
- "Something went wrong" where the API gave a real message
- Colour as the sole carrier of state
- A dialog during a running trip except `D4`

**Analytics** (no PII, no coordinates)
`screen_view{name}` · `trip_started` · `trip_completed{duration}` · `inspection_submitted{outcome}` · `incident_reported{type,class}` · `sos_triggered{online}` · `sync_conflict{action,reason}` · `offline_duration{seconds}`

**Next:** Phase 10 — Interaction specifications.
