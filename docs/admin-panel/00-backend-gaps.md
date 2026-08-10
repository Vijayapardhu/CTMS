# Backend gap register

What the backend does not do, what each gap costs the panel, and the smallest
thing that would close it. No backend file was changed to *produce* this
register; G3-1 has since been fixed and is marked as such. Each gap is
classified:

| Class | Meaning |
|---|---|
| **G0** | Solvable entirely in the frontend, at acceptable cost |
| **G1** | Acceptable MVP limitation — document it and move on |
| **G2** | Strong candidate for a backend addition after the MVP |
| **G3** | Must be fixed before this panel is used on real students |

---

## G3-1 — A VIEWER could perform ten mutations — **FIXED**

**Severity was high. Closed by `fix(auth): enforce admin access levels on
mutations`.** Kept here in full because the reasoning is why the tests exist.

> **Correction to the discovery pass.** The probe tested eight routes and this
> register originally said eight; the access matrix said nine. Enumerating
> every mutating route mechanically found **ten** —
> `POST /consolidations/{id}/notify` and `.../reject` were both missed. All ten
> are now gated, and a test asserts the *rule* rather than the list, so an
> eleventh cannot appear unnoticed.

Authorization runs on two axes. `RoleAuthorize` and the policies decide *role*;
`RequireAccessLevel` decides *access level*. Every policy in `app/Policies`
asks only `isAdmin()` — none of them consults the access level. That is by
design and is documented in `RequireAccessLevel`'s own docblock.

The consequence: **a route with `RoleAuthorize:ADMIN` and no
`RequireAccessLevel` is open to every admin, including a VIEWER.** Ten mutating
routes were in that state.

Verified by probe on eight of them, VIEWER against OPERATIONS, controls
included — the remaining two were found by enumerating the router:

```text
POST   /preventive-maintenance                      VIEWER=422 OPERATIONS=422   admits viewer
POST   /consolidations                              VIEWER=422 OPERATIONS=422   admits viewer
POST   /trips/{id}/corrections                      VIEWER=422 OPERATIONS=422   admits viewer
POST   /attendance-discrepancies/{id}/review        VIEWER=422 OPERATIONS=422   admits viewer
POST   /notification-log/{id}/resend                VIEWER=404 OPERATIONS=404   admits viewer
POST   /consolidations/{id}/approve                 VIEWER=404 OPERATIONS=404   admits viewer
POST   /consolidations/{id}/execute                 VIEWER=404 OPERATIONS=404   admits viewer
DELETE /preventive-maintenance/{id}                 VIEWER=404 OPERATIONS=404   admits viewer

controls, correctly gated:
POST   /buses                                       VIEWER=403 OPERATIONS=422   gated
POST   /incidents/{id}/acknowledge                  VIEWER=403 OPERATIONS=404   gated
```

Identical status codes for VIEWER and OPERATIONS mean authorization was passed
and only the payload or a missing record stopped the request. The controls
prove the probe distinguishes the two cases.

Two of these matter more than the rest. `POST /trips/{id}/corrections` rewrites
a closed trip's attendance record, and `TripPolicy::correct` says in its own
comment that the record "is the evidence of what they did; letting them rewrite
it afterwards would make it worthless for the one purpose it exists to serve".
`POST /attendance-discrepancies/{id}/review` closes a dispute about whether a
student was on a bus. Read-only oversight should not be able to do either.

**Cost to the panel, before the fix.** The access matrix records what the
backend *enforces*, not what it ought to. Hiding a control is not enforcement —
anyone with a token and `curl` reached them.

**The fix.** `RequireAccessLevel` on all ten routes. No policy, service or
schema change.

**Done.** Six routes gated at `OPERATIONS` (trip corrections, all four
consolidation mutations plus create, both preventive-maintenance writes) and
two at `SUPPORT` (attendance-dispute review, notification resend). No policy,
service or schema change. `AdminAccessLevelTest` covers all four levels
against every route, plus a rule test that walks the router and fails if any
`RoleAuthorize:ADMIN` mutation lacks a level gate.

**Related, and NOT fixed — see G3-2.**

---

## G3-2 — Three self-service mutations admitted any admin — **FIXED**

Found while fixing G3-1. Closed by `fix(auth): close self-service
authorization boundary`. These carry no role gate at all, because they also
serve the subject themselves:

```text
PUT   /students/{id}          StudentPolicy::update      isAdmin() || own record
PUT   /users/{id}             UserPolicy::update         isAdmin() || self
PATCH /drivers/{id}/status    DriverPolicy::changeStatus isAdmin() || self
```

A VIEWER reaches all three, because the policies ask only `isAdmin()`.

**Why it needed a different fix.** Route middleware cannot express
"OPERATIONS **or** the subject themselves". Adding `access:OPERATIONS` would
stop a student editing their own contact details and a driver marking
themselves off duty — both deliberate features.

**The fix.** The three policies now ask `hasAccessLevel()` instead of
`isAdmin()`, after the subject check:

```text
PUT   /users/{id}          the subject, or SUPER_ADMIN
PUT   /students/{id}       the student, or OPERATIONS
PATCH /drivers/{id}/status the driver,  or OPERATIONS
```

`SUPER_ADMIN` for an account because creating and deactivating accounts
already live there; `OPERATIONS` for the other two because seating a student on
a route and assigning a driver a bus already do.

The privilege fields were never exposed and still are not: `UpdateUserRequest`
does not accept `role`, `is_active`, `is_system` or `access_level` at all, and
`StudentController` strips the paid entitlement for a non-admin caller.
`SelfServiceBoundaryTest` proves both, and that self-service still works.

**OpenAPI does not change.** The generated document reads route middleware, and
says in its own description that record-level scope is enforced by policy and is
not visible there. This fix is invisible to it by design.

---

## G2-1 — No fleet-wide live endpoint

The backend has `GET /trips/{id}/live` and `GET /trips/{id}/eta`, both per-trip.
There is no `/fleet/live`.

**Cost.** A1 Dashboard and A2 Live Operations both want every running bus at
once. With `N` running trips one refresh costs:

```text
1  GET /trips?status=RUNNING&date=today
N  GET /trips/{id}/live
N  GET /trips/{id}/eta?stop_id=<next stop>       (stop_id is REQUIRED for an admin)
──
2N + 1 requests per cycle
```

For a college running twelve buses that is **25 requests every cycle**. At a
10-second interval, 150 requests a minute from one open browser tab — before a
second operator opens the same screen.

**Decision for the MVP: Option B + C, bounded polling.** Stated in full in
`09-screen-specifications.md` (A2) and `04-state-machines.md` (M-LIVE):

- The trip list refreshes on a **30 s** cycle
- `live` is fetched only for trips **currently rendered on the map viewport**,
  capped at **12** concurrent trips, ordered by severity then departure time
- `eta` is fetched only for the **selected** trip, not for all of them
- Polling pauses when the tab is hidden (`visibilitychange`) and after three
  consecutive failures, which is the same reachability rule the driver app uses
- The map shows a "showing 12 of N" chip when the cap bites, because a silently
  truncated fleet view is worse than a small one

Worst case becomes `1 + 12 + 1 = 14` requests per 30 s. That is a convincing
prototype without a new endpoint.

**Smallest backend fix, for later.** One endpoint,
`GET /fleet/live?date=today`, returning the array `TrackingController@live`
already builds, for every running trip the caller may view. It is a loop over
existing service code and one policy check. **Phase 2, not MVP.**

---

## G2-2 — No fleet-wide inspection list

The only inspection reads are `GET /inspections/checklist`,
`GET /inspections/{id}` and `GET /buses/{id}/inspections`. Nothing lists
inspections across the fleet, and nothing filters by date or outcome.

**Cost.** A11 Inspections cannot be built as a list screen without fetching
every bus and then one inspection list per bus — an N+1 against a growing
table, on a screen nobody is waiting for.

**Decision for the MVP.** A11 is **not** a fleet-wide list. It is:

- **Today's failures only**, derived from `GET /buses` plus
  `GET /buses/{id}/service-readiness` for buses that are not cleared. Readiness
  is one call per bus but only for buses the fleet list already says are
  blocked, which on a normal morning is nought to three
- Full inspection history stays where the backend supports it: on **A6 Bus
  Details**, from `GET /buses/{id}/inspections`

The navigation entry is "Inspections" and it opens the failures view. It is
honest about being today-only.

**Smallest backend fix, for later.** `GET /inspections?date=&outcome=&bus_id=`
— an index method on the existing controller and one policy check. **Phase 2.**

---

## G1-1 — No dashboard aggregation endpoint

There is no `/dashboard` or `/summary`. Every tile on A1 is composed on the
client from list endpoints, using `pagination.total` rather than counting rows.

**Cost.** Six parallel requests on first paint. That is acceptable, but only
with a deliberate loading strategy — see A1 in `09-screen-specifications.md`.
The rule is: **the page renders its skeleton once, and each tile resolves
independently into place**; no tile is allowed to shift the layout when it
lands, and one failed tile shows a retry inside its own card rather than
taking the page down.

**Classified G1, not G2.** An aggregation endpoint would be nice and is not
worth a backend change for a prototype. Six cached GETs are fine.

---

## G1-2 — Incidents cannot be filtered by severity or date

`IncidentController@index` accepts `status`, `type`, `bus_id`, `per_page`.
It does **not** accept `severity`, `from` or `to`.

**Cost.** A8 Incidents wants "critical first, then active, then recent". With
`per_page` capped at 100, sorting by severity on the client sorts only the page
in hand, which is a lie on any fleet with more than 100 incidents on file.

**MVP approach.** A8 defaults to `status=REPORTED` and `status=ACKNOWLEDGED`
— the open queue, which is small — and sorts those client-side by severity.
Historic incidents are reachable by `type` and `bus_id` only, and the screen
says so rather than offering a severity control that would quietly under-report.

**Smallest backend fix, for later.** Two more keys in the existing validator.

---

## G1-3 — Reports have no export

`ReportController` returns JSON. There is no CSV or XLSX endpoint; the class
comment says export "has its own rules" and was deliberately left out.

**MVP approach.** A15 renders the report and offers **client-side CSV** of
exactly the rows on screen, generated in the browser from the JSON already
fetched. The button says "Download this table", not "Export", because it is the
current view and not an authoritative extract.

**Not classified G3** even though exports are privileged: the client-side CSV
contains only data the server already returned to this user, so it creates no
new exposure. A server-side export with its own permission is a real feature
for later.

---

## G1-4 — Announcement delivery is not per-announcement

`GET /notification-log` and `/notification-log/health` exist, and
`DeliveryStatus` is a real enum (`QUEUED SENT DELIVERED RETRYING
PERMANENTLY_FAILED SUPPRESSED`). Nothing joins a delivery row to the
announcement that caused it.

**MVP approach.** A14 shows announcement state (draft, published, withdrawn)
from the announcement itself. Delivery health is a **separate** panel on A13
fed by `/notification-log/health`. The two are not presented as one number.

---

## G0-1 — Driver documents are not exposed as a list

`GET /drivers/{id}` returns the driver; there is no `/drivers/{id}/documents`
matching `/buses/{id}/documents`. Licence fields live on the driver record.

**Frontend resolution.** A7 reads licence number, class and expiry from the
driver record itself. No gap for the MVP.

---

## G0-2 — Notification priority vocabulary differs from the driver app

Backend `NotificationPriority` is `CRITICAL | STANDARD`. The driver app's
`AlertPriority` parses `critical`, `high`, `normal`, `low` and falls through to
normal for anything unrecognised, so `HIGH` and `LOW` are dead branches.

**Frontend resolution.** The panel implements exactly the two the backend has.
No behaviour change on either side; recorded so the next person does not add a
`HIGH` chip that can never render.

---

## Summary

| ID | Class | Gap | MVP impact |
|---|---|---|---|
| G3-1 | ~~G3~~ | ~~VIEWER can perform 10 mutations~~ | **FIXED** — server-enforced |
| G3-2 | ~~G3~~ | ~~3 self-service mutations admit any admin~~ | **FIXED** — policy-level, server-enforced |
| G2-1 | G2 | No fleet-wide live endpoint | Bounded polling, 12-trip cap |
| G2-2 | G2 | No fleet-wide inspection list | A11 is today's failures only |
| G1-1 | G1 | No dashboard aggregation | 6 parallel gets, per-tile loading |
| G1-2 | G1 | Incidents lack severity/date filters | Open queue only, sorted client-side |
| G1-3 | G1 | Reports have no export | Client-side CSV of the visible table |
| G1-4 | G1 | Delivery not joined to announcement | Two separate panels |
| G0-1 | G0 | No driver document list | Read from the driver record |
| G0-2 | G0 | Priority vocabulary differs | Implement the two that exist |
