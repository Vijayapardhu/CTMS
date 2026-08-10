# Operational workflows

The state machines the panel drives. Every state name is the backend's enum,
read from `app/Enums`. Every transition is an existing endpoint. Nothing here
is invented, and where the backend sets a state the panel cannot reach, that is
said rather than papered over.

Notation: `— tier →` is the access level the server enforces.

---

## W1 · Incidents

`IncidentStatus`: `REPORTED ACKNOWLEDGED IN_PROGRESS ESCALATED RESOLVED CLOSED`

```text
REPORTED ──acknowledge──▶ ACKNOWLEDGED ──resolve──▶ RESOLVED ──close──▶ CLOSED
   │      — SUPPORT →          │       — SUPPORT →      │   — OPERATIONS →
   └──────────── cancel ───────┴────────────────────────┘
                — SUPPORT → (reporter exception)
```

**`IN_PROGRESS` and `ESCALATED` are set by the backend**, by escalation rules
the panel does not drive. They render as states and filter like any other; there
is no button that produces them, and none is invented.

| Action | Capability | Confirm | Notes |
|---|---|---|---|
| Acknowledge | `incident.acknowledge` | no | "I have seen this." A dialog during an emergency buys nothing |
| Resolve | `incident.resolve` | yes | Names the incident and the bus |
| Close | `incident.close` | yes | Ends the operational record |
| Add note | `incident.note` | no | Running commentary |
| Cancel | `incident.cancel` | yes | Withdraws an alert others may have acted on |

Evidence is shown in place, from `GET /evidence/{id}`, never as a constructed
URL. Severity `CRITICAL` uses the `emergency` token, which is deliberately
darker than `critical` so SOS stays findable.

---

## W2 · Maintenance

`MaintenanceStatus`: `OPEN SCHEDULED IN_PROGRESS COMPLETED CANCELLED`

```text
OPEN ──schedule──▶ SCHEDULED ──start──▶ IN_PROGRESS ──complete──▶ COMPLETED
  │  — SUPPORT →        │   — SUPPORT →      │      — OPERATIONS →
  ├── assign (SUPPORT; sets the mechanic, does not move the state)
  └──────────── cancel (OPERATIONS) ─────────┴──────────▶ CANCELLED
```

`assign` is modelled as an attribute change rather than a transition, because
that is what it is.

### Return to service — two calls, one intent

There is **no `return-to-service` endpoint**. A vehicle comes back by:

1. `POST /maintenance-tickets/{id}/complete` — OPERATIONS
2. `PATCH /buses/{id}/status` → `AVAILABLE` — OPERATIONS

The panel presents this as one guided step with a single confirmation naming the
bus, and issues two calls. Step 2 may be refused with a 409 if readiness still
blocks it; the reasons are shown as the server wrote them.

Opening a ticket from a failed inspection pre-fills the bus, the failed item and
the inspection reference — `maintenance.open`, SUPPORT.

---

## W3 · Replacement vehicle

`ReplacementStatus`: `RECOMMENDED APPROVED REJECTED DISPATCHED ARRIVED COMPLETED`

```text
RECOMMENDED ──approve──▶ APPROVED ──dispatch──▶ DISPATCHED ──arrived──▶ ARRIVED ──▶ COMPLETED
     │      — OPERATIONS →        — SUPPORT →                — SUPPORT →      (backend)
     └──reject (OPERATIONS)──▶ REJECTED
```

The two-tier split is the backend's and is the point: **approving costs money,
dispatching does not.** A supervisor moves an approved vehicle; only a transport
head decides there will be one.

`COMPLETED` is reached by the backend when the replacement takes over the trip.
No button produces it.

---

## W4 · Trip corrections

BR-258. The attendance record is the evidence of what a driver did.

```text
COMPLETED trip ──correct──▶ correction recorded, original preserved
                — OPERATIONS →
```

| | |
|---|---|
| Fields | `occupied_seat_count`, `booked_seat_count`, `odometer_start`, `odometer_end`, `notes` — `TripRecoveryService::CORRECTABLE`, and nothing else |
| Payload | `field`, `value`, `reason` (≥ 5 characters, required) |
| History | `original_value` → `corrected_value`, `corrected_by`, `created_at`, `reason` |
| Amendment | **Not possible.** No endpoint edits or removes a correction, and none is invented |

VIEWER and SUPPORT read the history and are offered no pen.

---

## W5 · Consolidations

`ConsolidationStatus`: `PROPOSED APPROVED EXECUTED REJECTED EXPIRED`

```text
PROPOSED ──approve──▶ APPROVED ──notify──▶ APPROVED ──execute──▶ EXECUTED
   │     — OPERATIONS →        — OPERATIONS →      — OPERATIONS →
   ├──reject──▶ REJECTED   (read-only)
   └──(time)──▶ EXPIRED    (backend; no button)
```

Every step is OPERATIONS — BR-361 says manager-only, and since G3-1 the router
enforces it. **`notify` does not change the state**: it tells the affected
passengers, and BR-363 turns on the order relative to `execute`. The action bar
is state-driven and offers only what the current state allows.

`EXPIRED` is the backend's, on elapsed time.

---

## W6 · Preventive maintenance

```text
create ──▶ schedule exists ──delete──▶ gone
— OPERATIONS →             — OPERATIONS →
```

No lifecycle beyond existence. Deletion confirms, names the bus and the
interval, and states that scheduled work will no longer be raised. Never
`window.confirm`.

---

## W7 · Attendance discrepancies

BR-266. A disagreement between the headcount and the boarding log.

```text
raised (backend) ──review──▶ reviewed
                 — SUPPORT →
```

The screen must separate **looking at the evidence** from **settling the
record**. A review requires a note, cannot be performed twice, and alters
neither count — it records a judgement about them. The confirmation says so.

---

## W8 · Notification delivery

`DeliveryStatus`: `QUEUED SENT DELIVERED RETRYING PERMANENTLY_FAILED SUPPRESSED`

```text
PERMANENTLY_FAILED ──resend──▶ QUEUED
                   — SUPPORT →
```

Only a failed delivery offers resend. The control is disabled while the request
is in flight, so a slow response cannot become three messages to a parent.

Delivery health (`/notification-log/health`) is a **separate** panel from an
administrator's own inbox, and from announcements: nothing joins a delivery row
to the announcement that caused it (G1-4).

---

## W9 · Announcements

```text
draft ──publish──▶ published ──withdraw──▶ withdrawn
      — OPERATIONS →          — OPERATIONS →
```

`AnnouncementAudience`: `ALL STUDENTS DRIVERS ADMINS`. The publish confirmation
names the audience in words, because "publish" and "tell every student in the
college" should not feel like the same click.

---

## W10 · Governance

```text
create account        — SUPER_ADMIN →   POST /users
activate / deactivate — SUPER_ADMIN →   PATCH /users/{id}/status
edit account          — SUPER_ADMIN, or the subject
subject-access export — SUPER_ADMIN →   POST /users/{id}/subject-access-export
```

BR-010 keeps a minimum number of administrators reachable, and the backend
refuses to deactivate the last one — the 409 is shown verbatim. An administrator
cannot deactivate their own account; the server enforces it and the panel does
not offer it.

Audit and data-access logs are read-only. There is no write path to the audit
trail, and the components have no edit affordance to remove.

---

## Not driven by the panel

| Workflow | Why |
|---|---|
| Start / complete a trip, board, alight, arrive, skip | The bus reports these. An office that can board students remotely has destroyed the evidence value of its own attendance record |
| Record an inspection | The driver performs it, at the vehicle |
| Ingest positions | The handset |
| Escalate an incident | Backend rules, on elapsed time |

The panel watches these and never performs them, regardless of who is
permitted to.
