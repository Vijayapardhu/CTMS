# Phase 3 — Complete Functionality Catalogue

Phase 2 documented what each screen *is*. This document covers the functional machinery that
either spans screens or runs without one: every confirmation dialog, every background process,
every status change, every notification trigger, every permission check.

---

## 1. Action taxonomy

Every user action in the system falls into one of six classes, and its class determines the
treatment it receives. This is the rule that decides "does this need a confirmation dialog?"
so the question does not have to be re-litigated per screen.

| Class | Definition | Treatment |
|---|---|---|
| **Read** | No state change | No confirmation. Logged only for personal data (BR-P2) |
| **Reversible write** | Can be undone with no side effect | No confirmation. Toast with undo |
| **Consequential write** | Changes state others depend on | Inline confirmation stating the effect |
| **Notifying write** | Sends messages to people | Confirmation showing the **recipient count** before sending |
| **Destructive** | Removes or irreversibly changes | Modal with typed confirmation of the entity name |
| **Safety-critical** | Affects a person's physical safety | Modal, explicit reason, immediate alert, non-cancellable audit |

### 1.1 Classification of every significant action

| Action | Class | Confirmation required |
|---|---|---|
| View any list or record | Read | — |
| Edit own profile | Reversible | — |
| Change own password | Consequential | States that all sessions end |
| Create bus / route / student / schedule | Consequential | — (form submission is the confirmation) |
| Change bus status | Consequential | States affected trips |
| Assign transport to a student | Consequential | Shows remaining capacity |
| Assign bus to driver | Consequential | — |
| Publish a roster | Notifying | Driver count |
| Edit a route with assigned students | Notifying | Student count + effective date |
| Send announcement / broadcast | Notifying | Recipient count per channel |
| Cancel a trip | Notifying + Consequential | Reason + passenger and guardian count |
| Merge two trips (consolidation) | Notifying + Consequential | Passenger impact, per-passenger delay |
| Retire a bus / route / driver | Destructive | Typed name |
| Delete a stop | Destructive | Typed name + affected student count |
| Deactivate a user | Destructive | States that sessions end immediately |
| Bulk delete / bulk deactivate | Destructive | Typed count + preview list |
| Data retention purge | Destructive | Preview of exactly what is removed |
| Approve a guardian link | Safety-critical | Evidence reference + approver identity |
| Raise SOS | Safety-critical | Press-and-hold; no cancel dialog (speed matters more) |
| Report a `CRITICAL` incident | Safety-critical | States that the bus goes out of service |
| Return a bus to service | Safety-critical | Certifying role + ticket completion |
| Override a capacity limit | Safety-critical | Mandatory reason, permanently recorded |
| Break-glass data access | Safety-critical | Reason, time-box, immediate super-admin alert |

---

## 2. State machines

Every status field in the system is a state machine with explicit legal transitions. An
implementation that permits an arbitrary status assignment has a defect, regardless of what
the interface offers.

### 2.1 Bus

```
                 ┌──────────────────────────────────────┐
                 │                                      │
                 ▼                                      │
          ┌─────────────┐  dispatch   ┌──────────┐  end trip
   ┌─────►│  AVAILABLE  ├────────────►│ RUNNING  ├───────┘
   │      └──┬───┬───┬──┘             └────┬──┬──┘
   │         │   │   │                     │  │
   │  service│   │   │retire         fault │  │pulled for service
   │         ▼   │   ▼                     ▼  ▼
   │  ┌────────────┐ ┌─────────┐    ┌────────────┐
   └──┤MAINTENANCE │ │ OFFLINE │    │ BREAKDOWN  │
      └──────┬─────┘ └────┬────┘    └──────┬─────┘
             │            │                │
             │   recommission              │ must be serviced
             │◄───────────┘                │
             │◄────────────────────────────┘
             └──► OFFLINE (retire)
```

**Legal transitions**

| From | To | Guard |
|---|---|---|
| AVAILABLE | RUNNING | Trip start; documents valid; driver assigned |
| AVAILABLE | MAINTENANCE / BREAKDOWN / OFFLINE | No active trip |
| RUNNING | AVAILABLE | Trip ended |
| RUNNING | BREAKDOWN | Incident reported |
| RUNNING | MAINTENANCE | Operations pulls it; trip must be reassigned first |
| MAINTENANCE | AVAILABLE | **Ticket closed by an authorised role** |
| MAINTENANCE | OFFLINE | Retired from service |
| BREAKDOWN | MAINTENANCE | Repair begins |
| BREAKDOWN | OFFLINE | Written off |
| OFFLINE | AVAILABLE / MAINTENANCE | Recommissioned |

**Forbidden and why:** `BREAKDOWN → AVAILABLE` — a bus that broke must be inspected before
carrying students again. This is the single most important guard in the fleet module.

### 2.2 Driver

| From | To | Guard |
|---|---|---|
| AVAILABLE | ON_TRIP | Licence valid; duty hours remaining; inspection complete |
| AVAILABLE | LEAVE / OFF_DUTY | No active trip |
| ON_TRIP | AVAILABLE | Trip ended |
| ON_TRIP | OFF_DUTY | Trip ended or reassigned; never mid-trip |
| LEAVE / OFF_DUTY | AVAILABLE | Licence still valid |

**Forbidden:** `ON_TRIP → LEAVE` directly. A driver cannot go on leave while responsible for
a bus full of students.

### 2.3 Trip

```
   ┌───────────┐  start   ┌─────────┐  end   ┌───────────┐
   │ SCHEDULED ├─────────►│ RUNNING ├───────►│ COMPLETED │ (terminal)
   └─────┬─────┘          └────┬────┘        └───────────┘
         │                     │
         │ cancel              │ cancel
         ▼                     ▼
   ┌───────────────────────────────┐
   │          CANCELLED            │ (terminal)
   └───────────────────────────────┘
```

Terminal states never reopen. A trip that ended wrongly is corrected by attributed adjustment
records, not by reverting its status.

**Guards on `SCHEDULED → RUNNING`:** bus AVAILABLE with valid documents · driver AVAILABLE
with valid licence and remaining duty hours · pre-trip inspection passed · within the start
window · not a suspended service day.

**Automatic transitions:** auto-cancel if never started past a cutoff; auto-complete if left
running past scheduled arrival plus buffer (flagged for review).

### 2.4 Incident

`REPORTED → ACKNOWLEDGED → IN_PROGRESS → RESOLVED → CLOSED`, with `ESCALATED` reachable from
any non-terminal state. Guards: acknowledgement records actor and time; resolution requires a
note; closing requires the linked maintenance ticket to be closed for vehicle-fault types.

### 2.5 Maintenance ticket

`OPEN → ASSIGNED → IN_PROGRESS → AWAITING_PARTS ⇄ IN_PROGRESS → COMPLETED → CLOSED`, plus
`CANCELLED` from any non-terminal state with a reason. Only `COMPLETED → CLOSED` by an
authorised role releases the bus back to `AVAILABLE`.

### 2.6 Others

| Entity | States |
|---|---|
| Route | `ACTIVE ⇄ MAINTENANCE`, `ACTIVE ⇄ INACTIVE`; retire from INACTIVE only |
| Student record | `ACTIVE ⇄ INACTIVE`, `ACTIVE ⇄ SUSPENDED`; leaving ACTIVE clears transport |
| Pass | `PENDING_PAYMENT → ACTIVE → EXPIRED`; `ACTIVE → SUSPENDED ⇄ ACTIVE`; `→ CANCELLED` |
| Request (route change, link, leave) | `SUBMITTED → UNDER_REVIEW → APPROVED \| REJECTED`; `WITHDRAWN` from any open state |
| Replacement | `RECOMMENDED → APPROVED → DISPATCHED → ARRIVED → COMPLETED`; `REJECTED` |
| Consolidation | `PROPOSED → APPROVED → EXECUTED`; `REJECTED`; `EXPIRED` if not decided before the cutoff |
| Notification | `QUEUED → SENT → DELIVERED → READ`; `FAILED → RETRYING → ESCALATED` |

---

## 3. Background processes

Every process runs without a user present. Each must be idempotent, must log its outcome, and
must fail loudly rather than silently.

| # | Process | Cadence | What it does | Failure behaviour |
|---|---|---|---|---|
| BG-01 | **Trip generation** | Nightly, 22:00 | Creates tomorrow's trips from the timetable, skipping non-operating days; builds manifests | Alerts operations; a manual re-run is available and idempotent per (schedule, date) |
| BG-02 | **Generation exception scan** | After BG-01 | Detects unstaffed schedules, unavailable buses, expired documents, duty-hour breaches | Publishes to the dashboard exception list |
| BG-03 | **Duty notification** | After BG-02 | Tells drivers tomorrow's duty; tells riders of changes | Retries; failures surface in the notification log |
| BG-04 | **Position ingest** | Continuous during trips | Validates and stores positions; rejects implausible ones | Rejected points are logged, never stored as truth |
| BG-05 | **Geofence evaluation** | On each position | Detects stop arrival and departure; fires approach notifications | Missed geofence falls back to manual stop marking |
| BG-06 | **ETA recalculation** | On each position | Recomputes arrival estimates per remaining stop | Falls back to schedule-based estimates, labelled as such |
| BG-07 | **Delay detection** | Every minute per running trip | Compares actual to planned; fires delay notifications past threshold | Retries |
| BG-08 | **Stalled-trip detection** | Every minute | Flags trips with no position for N minutes | Raises an operations alert |
| BG-09 | **Trip auto-close** | Every 15 min | Closes trips left running past arrival + buffer | Flags for review; never silently discards attendance |
| BG-10 | **Off-route detection** | On each position | Detects deviation beyond a corridor | Alerts operations; suppressible per trip once accepted |
| BG-11 | **Consolidation analysis** | Hourly during service | Proposes merges for low-occupancy trips | Proposals expire if not decided |
| BG-12 | **Replacement recommendation** | On qualifying incident | Ranks candidate buses by proximity and availability | Falls back to a manual selection list |
| BG-13 | **Notification dispatch** | Continuous | Sends queued notifications per user preference and channel | Retries with backoff; escalates critical classes to an alternate channel |
| BG-14 | **Document expiry scan** | Daily | Flags expiring and expired vehicle documents and driver licences | Raises blocking alerts at expiry |
| BG-15 | **Pass expiry scan** | Daily | Expires passes; warns before expiry | Notifies student and guardians |
| BG-16 | **Preventive maintenance scan** | Daily | Opens tickets for services due by date or odometer | Alerts maintenance |
| BG-17 | **Dues reminder** | Weekly | Reminds on outstanding balances | Escalates by age |
| BG-18 | **Scheduled reports** | Per configuration | Generates and delivers reports | Notifies the owner on failure |
| BG-19 | **Retention purge** | Daily | Removes data past its retention window | Dry-run first; refuses to break referential history |
| BG-20 | **Attendance reconciliation** | End of day | Compares headcounts to boarding events; flags discrepancies | Produces a review queue, not an auto-correction |
| BG-21 | **Offline sync reconciliation** | On driver reconnect | Merges queued actions by timestamp, absorbing duplicates by idempotency key | Conflicts surface for manual resolution |
| BG-22 | **Session and token cleanup** | Hourly | Purges expired revocation entries | Silent |
| BG-23 | **Search index refresh** | Continuous | Keeps global search current | Degrades to direct query |

---

## 4. Notification trigger catalogue

Every message the system sends, who receives it, and under what rule. This is the complete
list — a notification not on this list is not sent.

| # | Trigger | Recipients | Channels | Critical? | Rule |
|---|---|---|---|---|---|
| N-01 | Trip started | Assigned passengers; their guardians | Push, in-app | No | Once per trip |
| N-02 | Bus approaching stop | Passengers at that stop; their guardians | Push | No | Once per stop per trip, on geofence entry |
| N-03 | Bus arrived at stop | Passengers at that stop | Push | No | Optional, per preference |
| N-04 | **Child boarded** | That student's guardians | Push, SMS | No | Per boarding event |
| N-05 | **Child alighted** | That student's guardians | Push, SMS | No | Per alighting event |
| N-06 | Trip delayed past threshold | Passengers downstream; guardians | Push | No | Once per threshold crossing, not per minute |
| N-07 | Trip cancelled | All assigned passengers; guardians | Push, SMS, in-app | **Yes** | Immediate; includes reason and alternative |
| N-08 | Stop skipped | Passengers at that stop; guardians | Push, SMS | **Yes** | Immediate |
| N-09 | Passengers left behind | Those students; guardians; operations | Push, SMS | **Yes** | Immediate; escalates if unresolved |
| N-10 | Trip completed | Guardians | Push | No | Per trip |
| N-11 | Incident reported | Operations; manager on severity | Push, in-app, email | **Yes** | Immediate |
| N-12 | Incident affecting a trip | Passengers aboard; passengers waiting — **different messages** | Push, SMS | **Yes** | Immediate |
| N-13 | **SOS raised** | Operations, manager, super admin | Push, SMS, call, in-app | **Yes** | Bypasses everything; every channel at once |
| N-14 | Replacement dispatched | Affected passengers; guardians | Push, SMS | **Yes** | With revised ETA |
| N-15 | Trips consolidated | Passengers of both trips | Push, SMS | **Yes** | **Before** the merge takes effect |
| N-16 | Duty assigned / changed | Driver | Push, in-app | No | On roster publication |
| N-17 | Roster published | All rostered drivers | Push | No | Once per publication |
| N-18 | Leave decision | Requesting driver | Push, in-app | No | On decision |
| N-19 | Maintenance ticket opened | Maintenance coordinator | In-app, email | No | On creation |
| N-20 | Bus out of service | Operations; affected schedule owners | In-app | **Yes** | Immediate |
| N-21 | Bus returned to service | Operations | In-app | No | On certification |
| N-22 | Document expiring | Manager, maintenance | Email, in-app | No | 30, 14, 7 and 1 days before |
| N-23 | Document expired | Manager, maintenance, operations | Email, in-app | **Yes** | Blocking |
| N-24 | Licence expiring | Driver, manager | Push, email | No | 30, 14, 7 and 1 days before |
| N-25 | Transport assigned | Student; guardians | Push, in-app, email | No | On assignment |
| N-26 | Transport changed | Student; guardians | Push, in-app | No | With effective date |
| N-27 | Route or timetable changed | Assigned students; guardians | Push, in-app, email | No | With effective date |
| N-28 | Request decision | Requester (student and/or parent) | Push, in-app | No | Approval and rejection alike, with reason |
| N-29 | Guardian link requested | Student; existing guardians; staff queue | Push, in-app | No | On submission |
| N-30 | Guardian link approved | Requesting parent; student; other guardians | Push, in-app | No | Transparency is the point |
| N-31 | Guardian link revoked | Affected parent; student | Push, in-app | No | Immediate |
| N-32 | Pass expiring | Student; guardians | Push, email | No | 14 and 7 days, and on expiry |
| N-33 | Pass expired | Student; guardians | Push, SMS, email | No | Blocks boarding |
| N-34 | Payment received | Student; paying guardian | Push, email | No | With receipt |
| N-35 | Dues outstanding | Student; guardians | Push, email, SMS | No | Weekly, escalating |
| N-36 | Announcement published | Targeted audience | Push, in-app | Depends | Urgent flag makes it critical |
| N-37 | Service suspended | All affected riders; guardians; drivers | Push, SMS, in-app | **Yes** | Immediate, with reason |
| N-38 | Account deactivated | That user | Email | No | Sessions end immediately |
| N-39 | Password changed | That user | Email | **Yes** | Security notice; sent even though the user did it |
| N-40 | New sign-in from an unrecognised device | That user | Push, email | **Yes** | With a secure-my-account action |
| N-41 | Break-glass access invoked | Super admin | Push, email | **Yes** | Immediate |
| N-42 | Stalled trip | Operations; the driver (prompt) | Push, in-app | **Yes** | Escalates |
| N-43 | Attendance discrepancy | Operations | In-app | No | End-of-day batch |
| N-44 | Report or enquiry response | Submitter | Push, in-app | No | On response |

**Suppression rules that apply to every non-critical notification:** quiet hours; per-category
mute; per-channel preference; deduplication within a window; batching where several events for
one recipient occur within a short interval.

**Rules that apply to critical notifications:** none of the above. They are delivered
regardless of preference, quiet hours or mute state, and failure on the primary channel
escalates to an alternate.

---

## 5. Permission check catalogue

Every check is applied server-side. The interface mirrors them for usability only.

### 5.1 Structural checks — run on every request

1. **Authenticated?** A valid, unexpired, non-revoked token of the correct type.
2. **Account live?** Re-read from storage; a deactivated account is refused immediately.
3. **Role permitted?** Does this role reach this capability at all?
4. **Record permitted?** Does this *specific* actor have a relationship to this *specific*
   record permitting this action?
5. **State permitted?** Does the target's current state allow this transition?
6. **Rate permitted?** Is the caller within limits?

A request must pass all six. Skipping check 4 is the defect that leaks other people's children.

### 5.2 Record-level rules, exhaustively

| Resource | Rule |
|---|---|
| User account | Self, or admin |
| Account status change | Admin, and never self |
| Role change | Super admin, and never self |
| Student record | Self, verified guardian, or staff with a student-data permission |
| Student location / attendance | Self, verified guardian, operations on duty, auditor. **Never** finance or support-without-need |
| Driver record | Self (limited fields), or manager/operations |
| Driver licence data | Manager only for write; driver may read own |
| Bus record | Read: any staff and any authenticated user for basic detail. Write: manager, maintenance |
| Bus status | Manager, maintenance, operations (in-trip transitions only) |
| Route and stops | Read: all authenticated. Write: manager |
| Schedule | Read: all staff. Write: manager |
| Trip | Read: staff, the assigned driver, assigned passengers, their guardians. Write: assigned driver (lifecycle only), operations |
| Trip position stream | Assigned driver writes; entitled viewers read while running |
| Boarding list | Assigned driver, for their own running trip, within the trip window |
| Attendance write | Assigned driver during the trip; operations afterwards as an attributed correction |
| Incident | Reporter reads own; operations and manager read all; nobody edits a submitted report |
| Maintenance ticket | Maintenance, manager; operations reads |
| Pass and payments | Self, verified guardian, finance |
| Notification | Recipient only |
| Audit log | Super admin full; auditor scoped; manager own domain |
| Announcement | Read by targeted audience; write by manager and operations |

### 5.3 Cross-cutting prohibitions

- No role may act destructively on its own account
- No role may grant itself a privilege it does not hold
- No role may read a minor's location outside the enumerated set, and every such read is logged
- Support and finance roles never gain live location access, however senior
- Impersonation is read-only by default, always banner-visible, always logged as *acted-by X
  as Y*, and always notified to the impersonated user
- Break-glass is time-boxed and self-expiring; it cannot be renewed silently

---

## 6. Validation catalogue

Validation happens in three layers. All three are required; none substitutes for another.

| Layer | Purpose | Authority |
|---|---|---|
| Client | Immediate feedback | None — advisory only |
| Server input | Shape, type, range, uniqueness, referential existence | Rejects with field-level detail |
| Server domain | Business rules and state machines | Rejects with a business-language reason |

### 6.1 Standard field rules

| Field type | Rules |
|---|---|
| Email | RFC format, ≤255, unique per account, institution domain where configured, normalised to lower case |
| Phone | Digits with optional country prefix, 7–15 digits, unique, normalised |
| Password | Minimum length and complexity per published policy, not reused from recent history, not a known-breached value |
| Name | 1–100 characters, no control characters |
| Registration / licence number | Unique, format per institution, normalised case |
| Vehicle registration | Unique case-insensitively, normalised to upper case |
| Coordinates | Latitude −90..90, longitude −180..180, within the configured service area |
| Capacity | Integer 1–120 |
| Times | `HH:MM` or `HH:MM:SS`, arrival strictly after departure |
| Dates | Valid; expiry dates in the future; ranges ordered |
| Money | Non-negative, 2 decimal places, explicit currency |
| Free text | Length-bounded, control characters stripped, rendered as text and never as markup |
| File upload | Type allowlist, size cap, content-type verified against actual content, stored outside the web root with a generated name |
| Search input | Treated as a literal; wildcard characters carry no special meaning |
| Page size | Capped server-side regardless of what is requested |

### 6.2 Domain rules by module

Enumerated in [01-system-analysis.md §3](01-system-analysis.md#3-business-rules). Each is
enforced at the service layer, tested from both the permitted and forbidden direction, and
returns a conflict rather than a validation error when the payload is well-formed but the
world disagrees.

---

## 7. Bulk operations

Bulk actions are where systems quietly corrupt themselves. Rules that apply to every one:

1. **Preview before commit.** Show exactly what will change, and for how many records.
2. **Per-item validation.** Each item is validated independently.
3. **Partial success is normal.** Report per-item outcomes; never fail the whole batch for one
   bad row, and never silently skip.
4. **Retry-failed-only.** After a partial run, the user can retry just the failures.
5. **Audited as a batch and as items.** One batch record, plus individual records.
6. **Asynchronous above a threshold**, with progress and a completion notification.
7. **Reversible where possible**, with the reversal itself audited.

Supported bulk operations: student transport assignment · student status change · pass
issue and renewal · bus and driver status change · schedule activation · trip cancellation ·
notification send · report generation · data import · data export · retention purge.

---

## 8. Import and export

### 8.1 Import pipeline

`Upload → Parse → Map columns → Validate every row → Preview → Match & merge → Commit →
Report`

- Templates are downloadable and versioned
- Column mapping is remembered per user
- Validation is complete before any row commits
- Matching against existing records uses a defined key (registration number, licence number,
  vehicle registration), with ambiguous matches sent to a human review queue rather than
  guessed
- The outcome report lists every row: created, updated, skipped, failed, with reasons
- Imports containing personal data are audit-logged with the row count

### 8.2 Export rules

- Honours active filters and field-level permissions
- Formats: CSV, XLSX, PDF for reports
- Asynchronous above a threshold, delivered by notification with a time-limited link
- Every personal-data export is audit-logged with requester, scope and row count
- Bulk personal-data export requires elevated privilege and a stated reason

---

## 9. Real-time channel model

| Channel | Subscribers | Events |
|---|---|---|
| Trip stream (per trip) | Assigned passengers, their guardians, operations, the driver | Position, stop arrival, boarding count, delay, status change |
| Fleet stream | Operations, manager | All running trips' summary state |
| Alert stream | Operations, manager, maintenance (own categories) | Alerts, SOS, incidents |
| User stream (per user) | That user only | Notifications, request decisions |

**Authorisation is applied at subscription time and re-checked on reconnect.** A subscription
that was valid when the trip started must not survive the trip ending, or the student's
entitlement being revoked. Reconnection uses backoff with jitter; on reconnect the client
requests a state snapshot rather than assuming continuity of the event stream.

---

## 10. Offline capability matrix

| Capability | Driver | Student | Parent | Admin |
|---|---|---|---|---|
| Read cached duty / schedule | Yes | Yes | Yes | No |
| Read cached route and stops | Yes | Yes | Yes | No |
| Start / end trip | **Yes, queued** | — | — | No |
| Mark stop arrival | **Yes, queued** | — | — | — |
| Passenger count +/− | **Yes, queued** | — | — | — |
| Report incident | **Yes, queued** | No | No | No |
| SOS | **Yes — falls back to call and SMS** | — | — | — |
| Emergency contacts | **Yes, without a session** | Yes | Yes | — |
| Mark absence | — | No | No | — |
| Any write | Queued | Refused with explanation | Refused with explanation | Refused |

**Sync rules.** Every queued action carries a client-generated idempotency key and its true
local timestamp. On reconnect, actions replay in timestamp order; duplicates are absorbed by
key; server timestamps remain authoritative for ordering when clocks disagree beyond
tolerance; conflicts that cannot be resolved automatically (a trip closed by a supervisor
while the driver was offline) are surfaced to the driver for explicit resolution rather than
being discarded or blindly applied.
