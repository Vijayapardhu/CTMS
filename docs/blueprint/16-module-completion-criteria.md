# 16 — Module Completion Criteria

Without a written definition, "done" means "the happy path worked when I tried it." This
document makes completion a checklist that can be failed.

**The governing principle:** a module is complete when the tests prove it, not when the code
exists. Documentation asserting completion is not evidence. Passing tests are evidence.

---

## 1. Completion levels

A module does not go from nothing to done. It passes through four levels, and each is claimed
explicitly. Claiming a level you have not reached is the failure this document exists to
prevent.

| Level | Name | Means |
|---|---|---|
| **L0** | Implementation Complete | The code is written. Nothing about it is yet trustworthy |
| **L1** | Module Internally Verified | The module stands on its own: every rule it owns is implemented and proven by test |
| **L2** | Cross-Module Integration Verified | Its integrations exist and are tested — events published, notifications delivered, jobs running |
| **L3** | UI + End-to-End Verified | A user can actually exercise the feature through the interface |

**Only L3 is "done".** L0, L1 and L2 are legitimate states and must be reported as such —
"Module 3 is at L1" is a useful, honest statement. "Module 3 is complete" when it is at L1 is
not.

**The distinction that matters most is L1 versus L2.** A module can be internally perfect and
still be capped below L2 because something it depends on does not exist yet. That is a
*dependency* condition, not a defect in the module, and the two must never be reported the
same way. A module held at L1 by a missing dependency is accepted work; a module held at L1 by
missing tests is not.

---

## 2. L1 — Module Internally Verified

### 2.1 Data layer

- [ ] Migration applies cleanly on an empty database
- [ ] Migration rolls back cleanly (`migrate:rollback` tested, not assumed)
- [ ] Every enum column has a matching `App\Enums` backed enum with identical values
- [ ] Constraints protecting concurrency-sensitive invariants exist **at the database level**,
      not only in a service (BR-057, BR-200 pattern)
- [ ] Indexes on every foreign key and every column used for filtering
- [ ] Soft deletes where history references the record
- [ ] Factory produces valid data satisfying every constraint, including CHECK constraints
- [ ] Factory states exist for each meaningful variant (`->inMaintenance()`, `->licenceExpired()`)
- [ ] Seeder produces a realistic, usable dataset

### 2.2 Model layer

- [ ] Relationships defined in both directions where both are used
- [ ] Every attribute cast — enums, dates, booleans, decimals
- [ ] `$fillable` reviewed against §2.2 of [15](15-developer-handbook.md); no privileged field
      present
- [ ] `$hidden` covers every secret
- [ ] Scopes for reusable query fragments
- [ ] Derived state exposed as methods, not recomputed at call sites

### 2.3 Business logic

- [ ] Every `BR-nnn` for this module is implemented
- [ ] Every state machine is on its enum, with `canTransitionTo()`
- [ ] Multi-step writes are wrapped in a transaction
- [ ] Concurrency-sensitive reads use `lockForUpdate()`
- [ ] Every mutation writes an audit record
- [ ] Domain exceptions carry the right status: 409 for state, 422 for payload

### 2.4 API layer

- [ ] Every endpoint in [07-api-specification](../07-api-specification.md) exists
- [ ] Every endpoint authenticated unless deliberately public, and the deliberation is written
      down
- [ ] Role gate on every endpoint
- [ ] Record-level `authorize()` on every endpoint touching a specific record
- [ ] FormRequest on every write
- [ ] Controllers are authorize → validate → delegate → respond
- [ ] Responses use the standard envelope
- [ ] Page size capped server-side
- [ ] Every error path returns a catalogued `ERR-nnn`

### 2.5 Tests — the gate

- [ ] All seven cases from [15 §11.3](15-developer-handbook.md) for **every** endpoint
- [ ] Every `BR-nnn` for this module has a test asserting **both** directions
- [ ] Every state machine tested: each legal transition succeeds, each illegal one returns 409
- [ ] Cross-user access test for every record-scoped endpoint
- [ ] Concurrency test for every rule protected by a database constraint
- [ ] `php artisan test` — full suite green, output recorded in the PR
- [ ] The [13](13-business-rule-catalogue.md) "Verified by" column filled for this module

### 2.6 Hygiene

- [ ] Zero `TODO`, `FIXME`, `XXX`
- [ ] Zero placeholder or stub methods
- [ ] Zero `dd()`, `dump()`, `var_dump()`, commented-out code
- [ ] Pint clean
- [ ] No secret in code, logs, audit or error responses

---

## 3. L2 — Cross-Module Integration Verified

- [ ] Every event this module should publish is published, with the naming convention from
      [15 §10](15-developer-handbook.md)
- [ ] Every notification trigger from [08 §4](08-functionality.md) belonging to this module
      fires, to the correct recipients, honouring critical-vs-suppressible rules
- [ ] Every background process from [08 §3](08-functionality.md) belonging to this module
      exists, is idempotent, and fails loudly
- [ ] Job failure raises an operations alert
- [ ] Broadcast channels authorized at subscribe **and** at reconnect
- [ ] Downstream modules consuming this module's events are tested against it
- [ ] Notification failure degrades and does not block the publisher (BR-408)
- [ ] Audit entries appear in the audit log screen and are queryable

---

## 4. L3 — UI + End-to-End Verified

### 4.1 Interface

- [ ] Every screen for this module from [03–07](03-screens-shared-auth.md) exists
- [ ] Navigation reaches every screen; every documented entry and exit point works
- [ ] Components come from [11](11-ui-component-library.md); no bespoke elements
- [ ] Tokens from [12](12-design-system.md); no raw values
- [ ] Loading, empty, error and success states implemented on every screen
- [ ] Empty states name the next action
- [ ] Disabled actions expose their reason
- [ ] Every error surfaces its catalogued message, not a raw status
- [ ] Offline behaviour per [08 §10](08-functionality.md) where applicable

### 4.2 Accessibility

- [ ] Keyboard reachable end to end, no traps
- [ ] Focus visible at 3:1
- [ ] Screen-reader tested on each platform this module ships to
- [ ] Contrast verified: 4.5:1 body, 3:1 large and UI
- [ ] Colour never the sole carrier of meaning
- [ ] Touch targets ≥44×44pt
- [ ] `prefers-reduced-motion` honoured

### 4.3 Operability

- [ ] Logs carry the correlation identifier
- [ ] Metrics exposed for the module's critical operations
- [ ] Alerts defined for its failure modes
- [ ] Runbook entry: what breaks, how it looks, what to do
- [ ] Rollback plan for the migration
- [ ] Load-tested at the morning-peak profile, not at average

### 4.4 Documentation

- [ ] API specification updated
- [ ] [13](13-business-rule-catalogue.md) "Verified by" complete for this module
- [ ] [14](14-error-catalogue.md) covers every error this module emits
- [ ] Screen specs match what was built — **or the specs are updated to match reality**
- [ ] Support quick-reference updated where users will hit new errors

---

## 5. The completion statement

A module is claimed complete only with this filled in and attached to the closing pull
request. Prose claims are not accepted.

```
MODULE:            <name>
LEVEL CLAIMED:     L0 | L1 | L2 | L3
DATE:

Endpoints:         <n> implemented / <n> specified
Business rules:    <n> implemented / <n> in catalogue for this module
Rules verified:    <n> with a passing test / <n> implemented
Screens:           <n> built / <n> specified          (L3 only)
Tests:             <n> passing, <n> failing, <n> skipped
Suite output:      <paste the actual final line>

NOT DONE (be specific — this section being empty is itself a claim):
  -

KNOWN DEFECTS:
  -

DEFERRED, WITH REASON:
  -
```

**"NOT DONE" being empty is a strong claim.** Reviewers should treat an empty section on a
non-trivial module as a reason to look harder, not as a reason to approve faster.

---

## 6. Current state — honest assessment

The criteria above, applied to what has actually been built. This is the first use of the
document, and it immediately finds that two modules were claimed complete when they were not.

### Module 0 — Foundation · **L1**

| Criterion | State |
|---|---|
| Enums, response envelope, exception mapping | Complete |
| JWT service with revocation, denylist, epoch | Complete |
| Middleware, rate limiters, policies, audit logger | Complete |
| Tests | Covered via Modules 1–2 suites |
| **Gap** | No dedicated unit tests for `TokenService` or the state-machine enums in isolation |

### Module 1 — Auth & Users · **L1**

```
Endpoints:      9 / 9
Rules verified: 12 of 20 identity rules
Tests:          80 passing (AuthenticationTest 27, RegistrationTest 19,
                PasswordTest 9, UserManagementTest 25)
```

**Not done:** MFA (BR-015) not implemented · two-super-admin minimum (BR-010) not implemented
· no notifications on account events (N-38, N-39, N-40) · guardian linking (BR-017, BR-018)
not started · API specification not updated.

### Module 2 — Fleet · **L1**

```
Endpoints:      15 / 15
Rules verified: 18 of 29 fleet and workforce rules
Tests:          68 passing (BusManagementTest 32, DriverManagementTest 36)
```

**Not done:** vehicle documents (BR-055) not implemented — a bus with expired insurance can
currently be assigned · duty hours (BR-106) not implemented · pre-trip inspection (BR-107) not
implemented · no notifications · no background jobs (BG-14 document expiry).

### Module 3 — Students, Routes, Schedules · **L1**

```
MODULE:            3 — Students, Routes, Stops, Schedules (FR-04, FR-05)
LEVEL CLAIMED:     L1
DATE:              2026-08-06

Endpoints:         24 / 24
Business rules:    31 implemented / 33 in catalogue for this module
Rules verified:    31 with a passing test / 31 implemented
Tests:             208 passing, 0 failing, 0 skipped
                     ModuleEnumTest              10
                     RouteStopTest (unit)         8
                     ScheduleTest (unit)         12
                     RouteManagementTest         32
                     RouteStopSequencingTest     37
                     ScheduleManagementTest      38
                     StudentManagementTest       38
                     TransportAssignmentTest     33
Suite output:      Tests: 356 passed (911 assertions)

NOT DONE:
  - BR-213 (notify assigned students when a route or timetable changes) —
    blocked on Module 6; no notification infrastructure exists yet
  - BR-212 (schedule edits must not alter already-generated trips) — cannot be
    tested until Module 4 creates trips; currently true only vacuously
  - L2 and L3 not attempted (see below)

KNOWN DEFECTS:
  - None outstanding. Two were found and fixed during this work:
    · Deleting any non-final stop returned a 500. The unique index on
      (route_id, sequence_number) counted soft-deleted rows, so closing the
      gap collided with the row just removed. Fixed by migration 000004,
      which makes the index partial on deleted_at IS NULL.
    · Updating an unrelated field on a schedule re-validated the unchanged
      route, so a schedule whose route was later emptied of stops could never
      be edited again — including to move it onto a valid route. Fixed by
      validating only the resources an update actually changes.

DEFERRED, WITH REASON:
  - Screens (24) — no frontend project exists; L3 requires standing up the
    client foundation and the 48-component library first
```

**Implemented during this pass:** BR-159 and BR-160 (route capacity with a reasoned,
separately-audited override) and BR-214 (service-area validation, including detection of
transposed latitude/longitude). All three were specified in the blueprint and absent from the
code.

### Modules 4–7 — **Not started**

Trips, tracking, attendance, incidents, maintenance, replacement, consolidation,
notifications, reports.

### Cross-cutting

| Area | State |
|---|---|
| Business rules verified | **64 of 157** |
| Notifications | Not implemented — no module emits any |
| Background jobs | None of the 23 exist |
| Front-end | None of the 207 screens exist |
| API specification | Stale — predates the Module 0 rewrite |

---

## 7. Build sequence

Revised 2026-08-06 after Module 3 reached L1. The original sequence ran Module 4 next; it was
changed because dependency order, not code volume, is now the limiting factor.

```
   Module 2 Safety Completion        BR-055 · BR-107 · BR-108
              │                      hard prerequisite for the trip-start gate
              ▼
   Module 6 — Notifications          unblocks L2 for Modules 1, 2, 3
              │                      device registration · preferences · delivery · retry
              ▼
   Modules 1–3 → L2                  emit the notifications they already should
              │
              ▼
   Module 4 — Trips & Tracking       publishes events; owns no notification logic
              │
              ▼
   Module 5 — Incidents & Maintenance
              │
              ▼
   Module 7 — Reports & Audit
```

### Why this order

**Module 2's safety gaps come first because BR-107 is a hard dependency.** Module 4's trip
start gate (BR-251) requires a passed inspection to exist. Building trip start before the
inspection record exists means refactoring the trip lifecycle afterwards, in the most
safety-critical code in the product.

**Module 6 comes before Module 4 because notifications are now the binding constraint.**
Three modules sit at L1 not because they are incomplete but because they cannot emit the
notifications the blueprint requires. Building the notification infrastructure lifts all three
at once and gives Module 4 an existing service to publish into, instead of embedding
delivery logic inside trip code:

```
   Trip Started ──► Event ──► Notification Service ──┬──► Student
                                                     ├──► Parent
                                                     ├──► Operations
                                                     └──► Supervisor
```

**Module 4 becomes materially simpler as a result.** It publishes domain events and owns none
of the addressing, preference, channel or retry logic.

## 8. Anti-patterns this document exists to stop

| Pattern | Why it is a lie |
|---|---|
| "Module complete" with a stale documentation file asserting it | This project already had a `COMPLETION_CHECKLIST.md` claiming a fully working authentication system while 13 of 14 tests failed |
| Happy-path-only tests | Proves the code runs, not that it is correct |
| "Tests are next sprint" | The tests are the completion criterion, not a follow-up |
| Stubs returning plausible values | Passes review, fails in production |
| Skipped tests left in the suite | A skipped test is a failing test with better manners |
| "It works locally" | Untested means unproven |
| Counting endpoints as progress | 24 endpoints with 0 tests is 0% complete |
| An empty "NOT DONE" section | Every non-trivial module has something not done |
