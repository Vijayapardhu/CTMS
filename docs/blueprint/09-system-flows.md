# Phase 4 — System Flows

Fourteen end-to-end flows. Each has a happy path, alternate paths, and failure scenarios.
The failure scenarios are the point — the happy path is the easy part.

Notation: **`▸`** a step · **`◆`** a decision · **`✗`** a failure branch · **`↻`** a loop.

---

## F-01 · User onboarding

### Student self-registration

```
▸ Student opens the app                                             ST/SH-01
▸ Chooses "Create account"                                          SH-02
▸ Enters identity, contact, credentials, registration number
◆ Registration number matched against the institutional roll?
  ├─ Yes  ▸ Account created as STUDENT, marked unverified
  └─ No   ▸ Account created, flagged PENDING_APPROVAL, queued to staff    AD-42
▸ Verification message dispatched                                   SH-04
▸ Student verifies
◆ Roll matched?
  ├─ Yes  ▸ Signed in; lands on home with "no transport assigned"    ST-01
  └─ No   ▸ Signed in; limited state until staff approve
▸ Staff assign transport                                            AD-39
▸ Student notified (N-25); home now shows route, stop and schedule
```

**Alternate paths**
- **Staff-created account** — staff create the student and assign transport in one pass
  (`AD-37` → `AD-39`); the student receives an invitation and sets their own password
- **Bulk import at term start** — `AD-41` → review queue → `AD-40` bulk assignment
- **Institutional SSO** — the account is provisioned on first successful SSO sign-in; the roll
  match is implicit

**Failures**
- ✗ *Email already registered* → field error with routes to sign-in and password reset
- ✗ *Registration number already claimed* → not auto-rejected; routed to staff, since the
  legitimate cause is usually a re-enrolment or a data-entry error
- ✗ *Verification message never arrives* → resend with cooldown; after N attempts, offer the
  alternate channel; ultimately a staff member can verify manually
- ✗ *Student registers but never gets transport assigned* → appears in the `unassigned` filter
  on `AD-35`, which is the term-start working queue. This is the most common real-world
  onboarding failure and it is caught by making it a first-class list filter rather than
  hoping someone notices

### Driver onboarding

```
▸ Manager creates the driver account and profile                    AD-27
▸ Licence, class and expiry recorded; documents uploaded            AD-31
◆ Licence valid and in the future?
  ├─ No   ✗ Creation refused — an expired licence cannot be onboarded
  └─ Yes  ▸ Driver invited; sets password; enrols MFA               SH-07
▸ Driver signs in; completes app permission setup                   DR-27
◆ Background location permission granted?
  ├─ No   ▸ Persistent warning; manager sees the driver as not track-ready
  └─ Yes  ▸ Ready
▸ Manager assigns a bus                                             AD-26
▸ Manager rosters the driver                                        AD-29
▸ Driver notified of duty (N-16)
```

---

## F-02 · Authentication

```
▸ Credentials submitted                                             SH-01
◆ Rate limit exceeded?  ├─ Yes ✗ 429 with retry time
◆ Account exists and password matches?
  ├─ No   ✗ Generic failure — identical for both causes
  └─ Yes  ◆ Account active?
           ├─ No  ✗ "Account deactivated" — safe to distinguish, since this
           │        branch is only reachable with a correct password
           └─ Yes ◆ MFA enrolled?
                    ├─ Yes ▸ Challenge                              SH-06
                    └─ No  ◆ Staff role?  ├─ Yes ▸ Forced enrolment SH-07
▸ Session established; landing by role
```

**Failure and edge scenarios**
- ✗ *Token expires mid-use* → `SH-08` preserves in-progress form content, re-authenticates,
  returns to the exact destination
- ✗ *Token revoked by logout-elsewhere or password change* → next request refused; explained
  as a security action, not a fault
- ✗ *Account deactivated while signed in* → refused on the very next request
- ✗ *Offline at sign-in* → cannot authenticate; an existing driver session continues to work
  offline, which is why sessions are long-lived on that client
- ✗ *MFA device lost* → recovery code path; if exhausted, staff-assisted reset with identity
  verification and a full audit entry

---

## F-03 · Guardian linking `[NEW]`

The security-critical flow of the whole product. A defect here exposes children.

```
▸ Parent creates an account and requests a link                     PA-20
▸ Enters child's registration number and date of birth
◆ Details match a student?
  ├─ No   ✗ Response is IDENTICAL to the match case — no enumeration
  └─ Yes  ▸ Request created; NOT granted
▸ Student and existing guardians notified (N-29)                    ST-20
▸ Staff queue receives the request                                  AD-45
◆ Verification path
  ├─ Student approves in-app                          → sufficient
  ├─ Existing verified guardian vouches               → sufficient
  ├─ Staff verify against institutional records       → sufficient
  └─ None of the above                                → remains pending
▸ Staff approve, choosing which data classes the link grants        AD-45
▸ Parent notified (N-30); child and other guardians notified too
▸ Parent's home now shows the child                                 PA-01
```

**Why it is built this way.** Any design where a parent's assertion alone grants access means
anyone who learns a registration number and date of birth can track a child in real time. The
identical response for matched and unmatched details prevents the screen being used to
discover which children exist. Notifying the student and existing guardians on *request*, not
just on approval, means an illegitimate attempt is visible to the people best placed to spot it.

**Alternate paths**
- Institution pre-loads guardian relationships at enrolment; the parent's account activation
  simply claims a pre-verified link
- Student initiates: invites a guardian by email, which is self-verifying

**Failures**
- ✗ *Repeated failed attempts across different registration numbers* → flagged to
  administrators as a probing pattern; rate-limited and eventually blocked
- ✗ *Custody dispute* → staff can revoke a link immediately (`AD-44`); the revocation is
  audited; historical notifications already delivered are not retracted
- ✗ *Parent has no smartphone* → SMS-only notification profile; no tracking, but boarding and
  alighting confirmations still arrive

---

## F-04 · Network setup (term start)

```
▸ Manager defines the service calendar: term dates, holidays        AD-61
▸ Creates routes                                                    AD-51
▸ Adds stops in order, with coordinates and geofences               AD-53
◆ Route has at least one stop?  ├─ No ✗ Cannot be scheduled
▸ Creates schedules: route + bus + driver + day + times             AD-58
◆ Conflict for that bus or driver on that day?
  ├─ Yes ✗ Refused; the conflicting schedule is named and linked
  └─ No  ▸ Schedule created
▸ Reviews the whole week                                            AD-60
▸ Publishes the roster; drivers notified (N-17)                     AD-29
▸ Assigns students to routes and stops                              AD-40
◆ Route capacity exceeded?
  ├─ Yes ▸ Warned; override requires a reason
  └─ No  ▸ Assigned; students and guardians notified (N-25)
```

**Failures**
- ✗ *No available driver for a schedule* → schedule saved but flagged unstaffed; appears on the
  dashboard exception list and blocks day approval in `AD-66`
- ✗ *More students than seats* → capacity planner (`AD-24`) models adding a bus; over-subscription
  requires explicit, reasoned override
- ✗ *Stop coordinates outside the service area* → refused at validation

---

## F-05 · Daily operations (the controller's day)

```
07:00 ▸ Controller opens the dashboard                              AD-01
      ▸ Reviews the overnight exception list
      ◆ Blocking exceptions?
        ├─ Yes ▸ Resolve each: reassign, substitute, cancel         AD-66
        └─ No  ▸ Approve the day
07:30 ▸ Opens the live map; watches departures                      AD-02
      ↻ For each trip: monitor start, progress, delay, occupancy
      ◆ Trip not started 10 minutes past window?
        └─ ▸ Alert raised → contact driver → start on their behalf, reassign, or cancel
      ◆ Trip stalled (no position)?
        └─ ▸ Alert raised → contact driver → confirm safe → resume or close manually
      ◆ Incident reported?
        └─ ▸ Enter F-11
08:30 ▸ Morning run complete; reviews anomalies                     AD-63
Midday▸ Triages incidents, requests, maintenance                    AD-42 AD-72 AD-78
16:00 ▸ Reviews consolidation proposals for the evening             AD-76
16:30 ▸ Evening run; same monitoring cycle
20:00 ▸ Reviews the day; writes the handover note                   AD-11
```

---

## F-06 · Trip generation

```
22:00 ▸ BG-01 runs for tomorrow
      ▸ Reads active schedules
      ◆ Is tomorrow an operating day?
        ├─ No  ▸ Generate nothing; record the reason
        └─ Yes ↻ For each schedule matching tomorrow's weekday and frequency:
                 ◆ Trip already exists for (schedule, date)?
                   ├─ Yes ▸ Skip — idempotent
                   └─ No  ▸ Create trip; build manifest from assignments minus absences
      ▸ BG-02 scans for exceptions
      ▸ BG-03 notifies drivers and affected riders
06:30 ▸ Controller reviews and approves the day                     AD-66
```

**Exception cases surfaced, not silently resolved**
- Schedule has no driver → unstaffed
- Assigned bus is in maintenance → needs substitution
- Driver is on approved leave → needs substitution
- Driver would breach duty hours → blocked
- Bus document expired → blocked (legal bar)
- Schedule has zero assigned passengers → proposed for cancellation

**Failures**
- ✗ *Generation job fails entirely* → operations alerted; manual re-run available and idempotent
- ✗ *Generation runs twice* → no duplicates, by construction
- ✗ *A schedule is edited after generation* → existing trips unaffected; the editor is told so

---

## F-07 · Trip execution

```
▸ Driver opens the app; sees today's duty                           DR-01
▸ Completes the pre-trip inspection                                 DR-03
◆ Safety-critical item failed?
  ├─ Yes ✗ Start BLOCKED; ticket opened; operations alerted (N-20)
  │        └─ ▸ Operations substitutes a bus → F-13, or cancels → N-07
  └─ No  ▸ Trip startable
▸ Driver confirms bus and starts                                    DR-04
◆ Guards: bus available · licence valid · duty hours · within window
  ├─ Any fail ✗ Refused, with the specific reason shown
  └─ All pass ▸ Trip → RUNNING; bus → RUNNING; driver → ON_TRIP
▸ Position streaming begins (BG-04); passengers notified (N-01)
↻ For each stop:
   ▸ BG-05 detects geofence entry → N-02 to students at that stop
   ▸ Driver marks arrival                                           DR-05
   ▸ Boarding: +1 per passenger                                     DR-05 / DR-06
     ◆ At capacity?
       ├─ Yes ✗ Refused → record "left behind" → N-09 → operations   AD-77
       └─ No  ▸ Count incremented; guardians notified (N-04)
   ▸ Driver departs the stop
   ◆ Stop skipped?  └─ ▸ Reason required → N-08 to those students
   ◆ Delay past threshold?  └─ ▸ BG-07 → N-06 to downstream passengers
▸ Final stop reached
▸ Driver ends the trip; confirms headcount and odometer             DR-08
◆ Headcount matches boarding events?
  ├─ No  ▸ Explanation required; discrepancy recorded, not reconciled away
  └─ Yes ▸ Clean close
▸ Trip → COMPLETED; bus → AVAILABLE; driver → AVAILABLE
▸ Attendance frozen; guardians notified (N-10)
```

**Failure scenarios**

| Failure | Handling |
|---|---|
| ✗ Driver never starts | Alert at threshold; operations starts on their behalf or reassigns |
| ✗ Connectivity lost mid-route | Everything continues locally and queues (DR-05 offline state). Students see the last known position with its age |
| ✗ Phone battery dies | Trip flagged stalled (BG-08); driver can resume on another device; supervisor can close manually |
| ✗ Driver ends the trip early | Reason required; remaining passengers notified; flagged for review |
| ✗ Driver forgets to end | Auto-closed (BG-09) and flagged; distinguishable from a clean close in every report |
| ✗ Bus breaks down mid-route | → F-11 and F-13; **passengers aboard and passengers waiting receive different messages** (N-12) |
| ✗ Off-route deviation | BG-10 alerts operations; controller accepts (suppressing further alerts) or intervenes |
| ✗ Wrong bus used | Recorded as a substitution at start; capacity recalculated; manifest limit adjusted |
| ✗ GPS unavailable throughout | Trip runs fully manually; students told the position is estimated |

---

## F-08 · Student journey

```
▸ Evening before: student sees tomorrow's schedule                  ST-08
  ◆ Not travelling?  └─ ▸ Marks absence (N to planning)             ST-09
▸ Morning: opens the app                                            ST-01
  ◆ Bus started?
    ├─ No  ▸ Countdown to scheduled departure
    └─ Yes ▸ Live tracking with ETA                                 ST-02
▸ Receives "bus approaching" (N-02); walks to the stop
▸ Boards; driver counts them on; guardians notified (N-04)
▸ Travels; can watch progress
▸ Alights; guardians notified (N-05)
▸ Attendance recorded and visible                                   ST-10
  ◆ Discrepancy?  └─ ▸ Reports it → staff review                    ST-23
```

**Alternate and failure paths**
- ✗ *Bus does not arrive* → student reports it (`ST-23`, safety category → routed straight to
  operations); operations sees the stop was skipped or the trip stalled and responds
- ✗ *Bus full* → student is flagged left-behind, notified explicitly (N-09), and appears in
  the `AD-77` queue with an escalation timer. Silence here is the failure mode that destroys
  trust
- ✗ *Trip cancelled* → N-07 with reason and alternative
- ✗ *Pass expired* → warned in advance (N-32); boarding refused; renewal path offered
- ✗ *Student at a different location* → nearby stops (`ST-06`) shows options, but boarding
  elsewhere requires a request, not self-service

---

## F-09 · Attendance

```
▸ Manifest built at generation from assignments minus absences      BG-01
▸ Driver sees expected passengers per stop                          DR-06
▸ At each stop: +1 per boarding, or named marking where enabled
  ◆ Student not on the manifest?
    └─ ▸ Confirm-and-record path; their own route is told they will not board
  ◆ At capacity?
    └─ ✗ Refused → left-behind record → N-09
▸ Alighting recorded (−1, or named)
▸ Trip closes; attendance frozen
▸ BG-20 reconciles headcounts against events
  ◆ Discrepancy?  └─ ▸ Review queue (N-43); never auto-corrected
▸ Corrections after close are attributed adjustment records that preserve the original AD-69
```

---

## F-10 · Guardian notification `[NEW]`

```
▸ Event occurs (boarding, alighting, delay, incident)
▸ System resolves recipients:
   ├─ The student
   └─ Every VERIFIED guardian whose link grants that data class
◆ Is the event safety-critical?
  ├─ Yes ▸ Send on all configured channels, ignoring quiet hours and mute
  └─ No  ▸ Apply preferences, quiet hours, deduplication and batching
▸ Dispatch (BG-13)
◆ Primary channel failed?
  ├─ Critical    ▸ Escalate to an alternate channel immediately
  └─ Non-critical▸ Retry with backoff; record the failure
▸ Delivery outcome recorded per recipient and channel                AD-94
```

**Failures**
- ✗ *Push service down* → critical classes fall back to SMS; operations sees the degraded
  banner on `AD-01`
- ✗ *Parent's device is off* → in-app history retains everything (`PA-11`); nothing is lost
- ✗ *Guardian link revoked between event and dispatch* → the notification is suppressed;
  entitlement is evaluated at dispatch time, not at event time

---

## F-11 · Emergency and incident

```
▸ Something goes wrong
◆ Immediate danger?
  ├─ Yes ▸ Driver raises SOS                                        DR-12
  │        ▸ N-13 to operations, manager, super admin on EVERY channel at once
  │        ▸ Persistent unmissable indicator on every staff screen   AD-74
  │        ▸ Controller acknowledges; opens a live channel to the driver
  │        ▸ Dispatches emergency services; notifies guardians of passengers aboard
  │        ▸ Resolves with an account of what happened
  └─ No  ▸ Driver reports an incident with type, severity, photo, location  DR-13
▸ BG: maintenance ticket opened automatically (N-19)                AD-78
◆ Severity HIGH or CRITICAL?
  ├─ Yes ▸ Bus → BREAKDOWN; removed from service (N-20)
  │        ▸ Replacement recommendation generated (BG-12) → F-13
  │        ▸ Passengers aboard and passengers waiting notified SEPARATELY (N-12)
  └─ No  ▸ Bus may continue; ticket queued for later
▸ Operations triages                                                AD-73
▸ Incident resolved and closed with a resolution note
```

**Failures**
- ✗ *SOS raised with no connectivity* → falls back to a direct call and SMS to the operations
  number; the in-app alert queues. An SOS that depends on data is not an SOS
- ✗ *False alarm* → cancellable within a grace window, but the event is **never erased**
- ✗ *Nobody acknowledges an SOS* → escalates automatically to the next contact tier
- ✗ *Incident during a trip with no replacement available* → operations arranges an
  alternative (another route's bus, external transport) and notifies passengers with a
  concrete plan rather than an apology

---

## F-12 · Maintenance

```
▸ Ticket created — automatically from an incident, from a failed
  inspection, from the preventive schedule (BG-16), or manually      AD-80
▸ Priority set from incident severity or service type
▸ Maintenance coordinator assigns a technician or workshop           AD-79
▸ Diagnosis, parts, labour and cost recorded
◆ Awaiting parts?  └─ ↻ Status cycles until available
▸ Work completed
◆ Bus fit to return to service?
  ├─ No  ▸ Ticket stays open; bus stays out of service
  └─ Yes ▸ Authorised role certifies → bus → AVAILABLE (N-21)
▸ Ticket closed; service history updated                             AD-82
```

**Failures**
- ✗ *Bus needed but not repaired* → stays out of service; scheduled trips are flagged for
  substitution. The system never returns an uncertified bus to service to relieve pressure
- ✗ *Repeat fault on the same vehicle* → surfaced in `AD-101` recurring-fault analysis
- ✗ *Preventive service overdue* → alert, then a hard block on assignment past the grace period

---

## F-13 · Replacement bus

```
▸ Trigger: HIGH/CRITICAL incident, or operations decision            AD-75
▸ BG-12 ranks candidates by proximity, availability, capacity, documents
▸ Operations reviews the ranked list
◆ Cost above the approval threshold?
  ├─ Yes ▸ Manager approval required; state shown as pending
  └─ No  ▸ Operations approves directly
▸ Replacement assigned; driver notified
◆ Does the replacement have capacity for all transferred passengers?
  ├─ No  ▸ Two replacements, or a second wave; passengers told which they are on
  └─ Yes ▸ Proceed
▸ Passengers and guardians notified with a revised ETA (N-14)
▸ Replacement dispatched → arrives → passengers transfer
▸ Attendance transfers to the replacement trip, preserving original boarding times
▸ Original trip closed with its reason; replacement trip runs to completion
```

**Failures**
- ✗ *No replacement available* → escalate to manager; options are external hire, a second run
  by another bus, or cancellation with a concrete alternative — every one of which is
  communicated, not left silent
- ✗ *Replacement also fails* → the incident chain is linked so the full sequence is
  reconstructable
- ✗ *Passengers dispersed before arrival* → attendance records who transferred and who did not

---

## F-14 · Consolidation

```
▸ BG-11 identifies low-occupancy trips on compatible routes
▸ Proposal generated: source, target, combined occupancy, fuel saved,
  added distance, added delay per passenger                          AD-76
▸ Manager reviews
◆ Combined passengers ≤ target capacity?
  ├─ No  ✗ Proposal invalid; not offered
  └─ Yes ◆ Added delay within the acceptable threshold?
           ├─ No  ▸ Flagged as poor passenger experience; approval discouraged
           └─ Yes ▸ Approvable
◆ Manager decision
  ├─ Reject ▸ Reason recorded; proposal closed; feeds the recommendation model
  └─ Approve▸ ▸ ALL affected passengers notified BEFORE the merge (N-15)
              ▸ Source trip cancelled; passengers reassigned to the target
              ▸ Target trip's route and timings adjusted
              ▸ Driver of the target notified of the change
```

**Failures**
- ✗ *Approved too late* — proposals expire at a cutoff; a merge cannot be executed against a
  trip already running past the divergence point
- ✗ *Passenger boards the cancelled bus anyway* → confirm-and-record at the door; they are
  carried, and the record reflects reality
- ✗ *Merged bus exceeds capacity because of unexpected boardings* → boarding refused at
  capacity as normal; left-behind handling applies (`AD-77`)

---

## F-15 · Reporting and administration

```
▸ Staff open the report library                                      AD-97
▸ Choose a report, set parameters, run
◆ Result size above the synchronous threshold?
  ├─ Yes ▸ Generated in the background; delivered by notification
  └─ No  ▸ Rendered immediately
▸ Export honours active filters AND field-level permissions
▸ Personal-data exports are audit-logged with requester, scope and row count
▸ Recurring reports are scheduled and delivered                      AD-103
```

**Administrative flows**
- **Retention purge** — dry run → preview of exactly what is removed → typed confirmation →
  execute → audit. Refuses to break referential history
- **Subject access request** — locate every record about one person across all modules →
  compile → deliver → log
- **Break-glass access** — invoke with a reason → time-boxed grant → super admin notified
  immediately (N-41) → auto-expires → full audit
- **Term rollover** — archive the completed term → roll the calendar forward → bulk-renew
  passes → rebuild the timetable → re-assign students → publish

---

## F-16 · Degraded operation

The flow nobody designs and everybody needs.

| Failure | System behaviour | Human procedure |
|---|---|---|
| Maps provider down | ETAs fall back to schedule-based, labelled as estimates; tracking continues on a schematic view | Controllers work from the trip list rather than the map |
| Push service down | Critical classes escalate to SMS; degraded banner on every staff screen | Operations broadcasts by SMS for anything urgent |
| SMS gateway down | Push and in-app continue; alert raised | Phone tree for genuinely critical contact |
| Database unavailable | Drivers continue fully offline and queue; staff surfaces show a maintenance state | Paper manifest fallback; reconcile on recovery |
| Whole platform unavailable | Driver apps keep running trips from cached duty; SOS falls back to voice | Printed daily duty sheet, produced each evening for exactly this case |
| Recovery | Queued driver actions replay by timestamp with idempotency; conflicts surface for resolution (BG-21) | Controller reviews the reconciliation queue before declaring normal service |

The last row is the reason the driver app's offline capability is a first-class requirement
rather than a refinement: **the buses run whether or not the server does.**
