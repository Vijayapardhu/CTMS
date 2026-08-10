# State machines

Nine machines. Screen-level machines describe what the panel shows; workflow
machines mirror backend transitions and invent none.

## Shared state vocabulary

Every data-bearing surface draws from one set. No screen uses all of it.

```text
Initial            nothing requested yet
Loading            first request in flight, nothing to show
Loaded             data on screen
Empty              the request succeeded and there is genuinely nothing
Refreshing         data on screen, a newer request in flight
PartialFailure     some of a composed screen failed, the rest is real
Unavailable        nothing on screen and the request failed
Forbidden          403 — this level may not read this
Unauthorized       401 — the session is over
Offline            three consecutive failures; the API is unreachable
```

Mutations add:

```text
MutationSubmitting  →  MutationSuccess
                    →  MutationRefused    409/422 — the server said no, with words
                    →  MutationFailure    5xx / network — nobody knows if it landed
```

`MutationRefused` and `MutationFailure` are **different states with different
copy**. Refused means the server considered it and declined; the message is
shown verbatim. Failure means the request may or may not have been received,
so the panel never says "not saved" — it says the result is unknown and offers
a refresh.

## Offline, precisely

Inherited from the driver app and not re-derived:

```text
3 consecutive API failures  →  Offline
any successful response     →  reachable
```

**Not** the browser's `navigator.onLine`. A laptop on café Wi-Fi with no route
to the college server is online by the radio and offline by CTMS. The banner
means the API is unreachable.

While offline: reads keep whatever they hold, marked stale; **every mutation
control is disabled**. This is a management panel — nothing here queues.

---

## M-DASH — Dashboard (A1)

```text
Initial ──▶ Loading ──▶ Loaded
                │           │
                │           ├─▶ Refreshing ─▶ Loaded
                │           └─▶ PartialFailure ─▶ (retry tile) ─▶ Loaded
                └─▶ Unavailable        every tile failed
```

Six independent requests, one page skeleton. Each tile owns its own state.

- The skeleton reserves each tile's final height, so nothing shifts on arrival
- A failed tile shows a retry **inside its own card**; the page stays up
- `Unavailable` only when *all six* fail — which is the offline case anyway
- Auto-refresh every 60 s, paused when the tab is hidden

---

## M-LIVE — Live Operations (A2)

The most constrained machine in the panel, because of G2-1.

```text
Initial ──▶ LoadingTrips ──▶ TrackingIdle ──┬─▶ Tracking ──▶ Tracking (30s cycle)
                  │                          │       │
                  │                          │       ├─▶ TrackingDegraded
                  │                          │       └─▶ Offline (3 failures)
                  │                          └─▶ Empty   no trip is running
                  └─▶ Unavailable
```

| Event | Effect |
|---|---|
| tick (30 s) | Refresh the trip list; refresh `live` for tracked trips only |
| trip selected | Fetch `eta?stop_id=<next pending>` for that trip alone |
| trip deselected | Stop fetching ETA |
| tab hidden | Pause every timer |
| tab shown | One immediate refresh, then resume |
| 3 consecutive failures | → Offline; markers freeze and are marked stale |
| 429 | → TrackingDegraded; interval doubles until a success |

**Tracked set:** at most **12**, chosen by severity (incident on the trip),
then scheduled departure. When `N > 12` the map shows "Tracking 12 of N" —
silent truncation would read as a smaller fleet than the college has.

**Request budget:** `1 + min(N,12) + (1 if selected)` per 30 s. Worst case 14.

---

## M-TRIP — Trip management (A3, A4)

Backend `TripStatus`: `SCHEDULED RUNNING COMPLETED CANCELLED`.

```text
SCHEDULED ──start (driver)──▶ RUNNING ──complete (driver)──▶ COMPLETED
    │                            │
    └──────── cancel ────────────┘   OPERATIONS
                                      ▼
                                  CANCELLED
```

The panel **cannot start or complete a trip.** Those are the driver's, and the
policy allows an admin only as a stand-in for a failed device — a workflow the
MVP does not build. The panel's transitions are:

| Action | Endpoint | Level | From | Refusal |
|---|---|---|---|---|
| Cancel | `POST /trips/{id}/cancel` | OPERATIONS | SCHEDULED, RUNNING | 409 verbatim |
| Reassign | `POST /trips/{id}/reassign` | OPERATIONS | SCHEDULED, RUNNING | 409 verbatim |
| Correct | `POST /trips/{id}/corrections` | ⚠ not enforced | COMPLETED | 422 field errors |

Cancel is confirmed with a dialog naming the trip and route, because it tells
every student on that route the bus is not coming.

---

## M-INC — Incident management (A8, A9)

Backend `IncidentStatus`, and only these transitions exist:

```text
REPORTED ──acknowledge──▶ ACKNOWLEDGED ──resolve──▶ RESOLVED ──close──▶ CLOSED
    │          SUPPORT          │          SUPPORT       │    OPERATIONS
    │                           │                        │
    └────────── cancel ─────────┴────────────────────────┘
               reporter or admin
```

`IN_PROGRESS` and `ESCALATED` are values the backend sets; **the panel has no
button that produces them** and does not pretend otherwise. They render as
states and are filterable.

| Action | Endpoint | Level | Confirm |
|---|---|---|---|
| Acknowledge | `POST /incidents/{id}/acknowledge` | SUPPORT | no |
| Resolve | `POST /incidents/{id}/resolve` | SUPPORT | yes |
| Close | `POST /incidents/{id}/close` | OPERATIONS | yes |
| Note | `POST /incidents/{id}/notes` | ⚠ | no |
| Cancel | `POST /incidents/{id}/cancel` | ⚠ | yes — it withdraws an alert |

Acknowledge is not confirmed: it means "I have seen this", and putting a dialog
in front of it during an emergency is a delay bought for nothing.

---

## M-MAINT — Maintenance (A10)

Backend `MaintenanceStatus`: `OPEN SCHEDULED IN_PROGRESS COMPLETED CANCELLED`.

```text
OPEN ──schedule──▶ SCHEDULED ──start──▶ IN_PROGRESS ──complete──▶ COMPLETED
  │   SUPPORT          │      SUPPORT        │       OPERATIONS
  ├──assign (SUPPORT, does not change status)
  └────────────── cancel (OPERATIONS) ───────┴──────────▶ CANCELLED
```

`assign` sets the mechanic or workshop without moving the ticket — modelled as
an attribute change, not a transition, because that is what it is.

**Return to service is two calls and one intent** (J10): complete the ticket,
then `PATCH /buses/{id}/status` to `AVAILABLE`. The second may be refused with
409 if readiness still blocks; the reasons are shown as written.

---

## M-REPL — Replacement vehicle (A9)

Backend `ReplacementStatus`:

```text
RECOMMENDED ──approve──▶ APPROVED ──dispatch──▶ DISPATCHED ──arrived──▶ ARRIVED ──▶ COMPLETED
     │       OPERATIONS              SUPPORT                 SUPPORT
     └──reject (OPERATIONS)──▶ REJECTED
```

`COMPLETED` is reached by the backend when the replacement takes over the trip;
the panel shows it and offers no button for it.

---

## M-INSP — Inspection review (A11)

Read-only. The panel never records an inspection.

```text
Initial ─▶ LoadingFleet ─▶ FilteringBlocked ─▶ LoadingReadiness ─▶ Loaded
                                                     │
                                                     └─▶ PartialFailure
```

Bounded by construction: readiness is fetched only for buses the fleet list
already reports as not `AVAILABLE`, which is normally nought to three. If more
than **eight** buses are blocked the screen fetches the first eight and says
so — that many blocked buses is itself the story.

---

## M-ANN — Announcement publishing (A14)

```text
Draft ──publish──▶ Published ──withdraw──▶ Withdrawn
      OPERATIONS              OPERATIONS
```

Publish is confirmed and the dialog names the audience — `ALL`, `STUDENTS`,
`DRIVERS` or `ADMINS` — and how many people that is, because "publish" and
"tell every student in the college" should not feel like the same click.

Delivery is **not** part of this machine. Whether a message reached a handset
lives in `notification-log` and is shown separately (G1-4).

---

## M-REPORT — Report generation (A15)

```text
Initial ─▶ Idle ─▶ Running ─▶ Loaded ─▶ (change range) ─▶ Running
                      │          │
                      │          └─▶ Empty      no rows in range
                      └─▶ Unavailable / Forbidden
```

Reports are on-demand, never polled. The range is in the URL so a report can
be linked to. Download builds CSV in the browser from the loaded rows.

---

## M-SESSION — Session (all screens)

Inherited wholesale from the driver app, because the token semantics are the
backend's and identical.

```text
Initialising ─▶ Authenticated
      │              │
      │              ├─▶ Refreshing ─▶ Authenticated
      │              │        └─────▶ Expired
      │              └─▶ Expired ─▶ Unauthenticated
      └─▶ Unauthenticated
```

Panel-specific rules:

- A user whose role is not `ADMIN` is signed out immediately with "This panel
  is for transport office staff." A driver's token is valid and irrelevant here
- The access level is read once from `auth/me` and drives every `can()` check
- A 401 mid-session triggers **one** refresh; a second failure ends the session
- **No offline session.** Unlike the driver app there is no cached-identity
  mode: a laptop that cannot reach CTMS has no work it can do here
