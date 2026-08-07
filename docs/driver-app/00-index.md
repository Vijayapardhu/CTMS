# CTMS Driver App — Specification

**Status:** all 11 phases complete · built against `v1.0.0-backend-freeze`
**Platform:** Flutter, Material 3, Android-first, tablet-responsive
**Contract:** the backend is frozen. Where this document and the API disagree, **the API is correct** and this document is the bug.

---

## The one thing that shapes every decision

The person using this app is driving a bus with children on it.

That is not a design flourish; it is a constraint that decides real questions. It is why the passenger counter is two enormous buttons and not a numeric field. It is why SOS takes one tap and asks for nothing. It is why the app never blocks on a network call during a trip. It is why an error message says what to do rather than what went wrong.

Anything that would make a driver look at the screen for longer than a glance while the vehicle is moving is a defect, not a feature.

---

## Documents

Written workflow-first: journeys, then structure, then visuals. The design
system is Phase 7 rather than Phase 1 because it is sized to what the
components actually needed, not invented up front.

| # | Document | What it settles |
|---|---|---|
| 00 | This file | Information architecture, navigation, implementation order |
| 01 | [API contract](01-api-contract.md) | The 68 endpoints a driver can reach, verified against the frozen backend |
| 02 | [User journeys](02-user-journeys.md) | 17 journeys, every fork a real API response |
| 03 | [Screen inventory](03-screen-inventory.md) | 4 roots, 21 push, 4 modal, 7 sheet, 6 dialog, 5 chrome |
| 04 | [State machines](04-state-machines.md) | 9 machines; these are the Bloc definitions |
| 05 | [Wireframes](05-wireframes.md) | Structure and hierarchy, no colour |
| 06 | [Component library](06-component-library.md) | 20 components, each demanded by ≥2 wireframes |
| 07 | [Design system](07-design-system.md) | Tokens sized to those 20 components |
| 08 | [Icon registry](08-icon-registry.md) | Hugeicons with verified Material fallbacks |
| 09 | [Screen specifications](09-screen-specifications.md) | Frontend contract per screen |
| 10 | [Interaction specifications](10-interaction-specifications.md) | Gestures, haptics, motion, a11y |
| 11 | [Flutter implementation guide](11-flutter-implementation-guide.md) | Architecture, packages, sync queue, build order |

---

## Information architecture

The app has **one primary object**: today's trip. Everything else is either a step toward starting it, an action taken during it, or a record of it afterwards.

```
                    ┌─────────────────────────────┐
                    │        TODAY'S TRIP         │
                    │   the app's centre of mass  │
                    └─────────────────────────────┘
                                  │
        ┌─────────────────────────┼─────────────────────────┐
        │                         │                         │
   BEFORE                      DURING                    AFTER
        │                         │                         │
  Service readiness         Live map + GPS            Trip summary
  Pre-trip inspection       Passenger boarding        Attendance record
  Evidence capture          Stop arrival / skip       History
  Start gate                ETA and delay
                            SOS · Breakdown
                            Replacement status
```

Everything outside that spine — notifications, profile, settings, help — is **secondary** and lives behind the last tab. A driver should never have to leave the trip spine to do their job.

### Navigation shape

Four bottom-tab destinations. Not five, not three.

```
┌──────────┬──────────┬──────────┬──────────┐
│   Trip   │   Map    │  Alerts  │    Me    │
│  (home)  │          │  (badge) │          │
└──────────┴──────────┴──────────┴──────────┘
```

- **Trip** — the default. Today's trip in whatever state it is in. This tab changes shape more than any other: it is the readiness checklist before departure, the running trip during, and the summary after.
- **Map** — full-screen live map. Separate from Trip because during a run the driver wants it filling the screen, not embedded in a card.
- **Alerts** — notifications. Badged with unread count.
- **Me** — profile, duty status, settings, offline queue, help, logout.

**SOS is not a tab.** It is a persistent element rendered above the tab bar during a running trip, reachable from every screen. Putting an emergency behind navigation is a design failure.

---

## Navigation map

```
Splash
  ├─ no token ─────────────────► Login
  │                                ├─ Forgot password ─► (out-of-band; see 09)
  │                                └─ success ─────────► Dashboard
  ├─ token, refresh fails ───────► Session expired ────► Login
  └─ token valid ────────────────► Dashboard

Dashboard (Trip tab)
  │
  ├─ no trip today ──────────────► Empty: "No trip assigned"
  │
  ├─ trip SCHEDULED
  │     ├─ Service readiness card
  │     │     ├─ not cleared ───► Blocked reasons (see 09)
  │     │     └─ no inspection ─► Pre-trip inspection
  │     │                            └─ item fails ──► Evidence capture
  │     │                                                 └─ Evidence preview
  │     └─ cleared ─────────────► Start trip (confirm) ─► Trip running
  │
  ├─ trip RUNNING
  │     ├─ Map (tab)
  │     ├─ Stop details ────────► Manifest · Arrive · Skip · Left behind
  │     ├─ Passenger boarding
  │     ├─ SOS (persistent) ────► SOS confirm ─────────► SOS active
  │     ├─ Report incident ─────► Incident type ─► Evidence ─► Submitted
  │     ├─ Replacement status (appears only when one exists)
  │     └─ End trip (confirm) ──► Trip summary
  │
  └─ trip COMPLETED / CANCELLED ► Trip summary ────────► History

Alerts ──► Notification detail ──► deep link into the object it concerns
Me ─────► Profile · Duty status · Settings · Offline queue · Help · About · Logout
```

### Back behaviour

| Context | Back does |
|---|---|
| Any tab root | Exits the app (Android predictive back) |
| Any pushed screen | Pops normally |
| **Trip running** | Never leaves the running trip; back from Map returns to Trip tab |
| Inspection in progress | Confirms discard before popping — a half-filled checklist is worth protecting |
| SOS confirm sheet | Dismisses without sending; requires no confirmation to cancel |
| **SOS active** | Cannot be dismissed by back. Only an explicit "Cancel SOS" with a reason (BR-355) |
| Evidence capture | Discards the photo, confirms first |
| Session expired | Cannot go back; clears the stack |

### Deep links

Notification taps route by `data.*` payload, never by parsing the body text.

| Payload key | Destination |
|---|---|
| `trip_id` | Trip detail for that trip |
| `incident_id` | Incident detail |
| `consolidation_id` | Trip detail with a merge banner |
| `ticket_id` | Maintenance detail (read-only for a driver) |
| `announcement_id` | Announcement detail |
| `route_id` | Route detail |
| none | Alerts tab |

If the target no longer exists or the driver may not see it, land on the Alerts tab with a snackbar — never a raw 403 or 404 screen from a notification tap.

---

## Implementation order

This is the order the user specified, and it is also the order that exercises the backend from the outside in. Each step is shippable and testable before the next begins.

| # | Slice | Endpoints exercised | Done when |
|---|---|---|---|
| 1 | **Authentication** | `POST /auth/login`, `/refresh`, `/logout`, `GET /auth/me` | A driver can sign in, the token survives a restart, and an expired token silently refreshes |
| 2 | **Today's Trip** | `GET /trips`, `/trips/{id}`, `/buses/{id}/service-readiness` | The trip card shows the real state and the blocked reasons are legible |
| 3 | **Pre-trip inspection** | `GET /inspections/checklist`, `POST /buses/{id}/inspections` | A full checklist submits and a safety-critical failure blocks the bus |
| 4 | **Evidence upload** | `GET /evidence/categories`, `POST /evidence`, `GET /evidence/{id}` | A failed item captures a photo, uploads it, and cites the id |
| 5 | **Start trip** | `POST /trips/{id}/start` | The gate's refusal reasons render as actionable text, not a 409 |
| 6 | **Live GPS** | `POST /trips/{id}/positions`, `GET /trips/{id}/live` | Position streams at interval, buffers offline, and replays without duplicates |
| 7 | **Boarding** | `POST /trips/{id}/board`, `/alight`, `/stops/{stopId}/arrive`, `/manifest` | Counting works one-handed and survives a dead network |
| 8 | **SOS** | `POST /incidents` | One tap, works offline, falls back to a phone call |
| 9 | **Breakdown** | `POST /incidents` with evidence | An operational incident cannot submit without a photograph |
| 10 | **End trip** | `POST /trips/{id}/complete` | The summary reconciles and the trip closes |

**Do not build 6 before 5.** The GPS pipeline refuses positions for a trip that is not `RUNNING`, so there is nothing to test.

**Build 4 before 3 is shippable.** The inspection cannot be submitted for a safety-critical failure without an `evidence_id`, so step 3 is only half-testable on its own.

---

## What the driver cannot do

Stated plainly, because the temptation during UI work is to add a button for it. Every one of these is enforced server-side and will return `403`.

| Not permitted | Why |
|---|---|
| Acknowledge, resolve or close an incident | Triage is an operations decision |
| Approve or reject a replacement bus | It costs money and pulls a vehicle off another duty (BR-359) |
| Sign off a maintenance ticket | That is the act that returns a vehicle to the road (BR-358) |
| Correct a closed trip's attendance | The record is the evidence of what they did (BR-258) |
| See another driver's incidents or trips | Record-level scoping |
| Change their own licence or assigned bus | Fleet decisions |
| Upload evidence for somebody else's report | `claim()` refuses it |

The app must not render controls for any of these. A disabled button a driver cannot explain is worse than no button.

## What the driver *can* do that is easy to miss

| Permitted | Where it appears |
|---|---|
| **Cancel their own SOS** (BR-355) | On the active SOS screen, with a required note |
| Add a note to their own incident | Incident detail |
| Set their own duty status | Me tab |
| Mark a stop skipped, with a reason | Stop details |
| Record students left behind | Stop details, after arrival |
| Read maintenance on their assigned bus | Trip tab, "why is this bus off the road" |
| Read announcements addressed to drivers | Alerts tab |
