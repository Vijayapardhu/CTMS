# User journeys

Seventeen operational stories. Each maps to real endpoints and real access
levels. Where a journey ends at a wall, the wall is named.

Read `AccessLevel` as: VIEWER = oversight, SUPPORT = supervisor,
OPERATIONS = transport head, SUPER_ADMIN = system administrator.

---

## J1 — Transport Head opens the dashboard

**OPERATIONS.** 07:40, before the first run.

1. Signs in. `POST /auth/login`, then `GET /auth/me` confirms `ADMIN` +
   `OPERATIONS` and the panel computes its capability set once.
2. A1 paints its skeleton immediately and fires six requests in parallel.
3. Within a second: 18 trips today, 12 buses available, 2 in maintenance,
   3 open incidents, 1 document expiring this week.
4. **Attention Required** lists what is not normal, worst first.

**Succeeds when** the head can tell whether today is normal without clicking.

---

## J2 — Supervisor checks today's trips

**SUPPORT.** 08:05.

1. A3, defaulted to `date=today`.
2. Sorts by departure. Four running, six scheduled, one cancelled.
3. Filters `status=RUNNING` to see only what is moving.
4. Clicks a row → A4.

Cancel and reassign are **absent**, not disabled: `SUPPORT` can never do them.

---

## J3 — Transport Head watches a running bus

**OPERATIONS.**

1. A2. `GET /trips?status=RUNNING&date=today` returns the running set.
2. Up to twelve are tracked; each gets `GET /trips/{id}/live` every 30 s.
3. Selects a bus. Only now does `GET /trips/{id}/eta?stop_id=<next>` fire.
4. The drawer shows driver, route, current stop, next stop, **road distance**
   (`distance_metres`, `~` if estimated) and ETA.
5. The polyline draws from the stop geometry; the completed leg is muted.

**Never** computes distance in the browser. The number beside the stop name is
the same number the driver is looking at.

---

## J4 — GPS goes stale

**Any level.**

1. A bus enters a valley. Positions stop arriving.
2. The server sets `is_stale: true` on `live`. The panel does not decide this
   and does not compute an age threshold of its own.
3. The marker desaturates to the stale accent, and the row says how old the
   fix is.
4. If the driver app raises `TRACKING_LOST`, it appears in Attention Required
   as an incident — a different thing from a stale marker, shown differently.

**The rule the driver app already enforces, inherited:** a stale position is
never drawn as a live one.

---

## J5 — An SOS arrives

**Any level sees it. SUPPORT+ can act.**

1. Driver holds the SOS control. Backend creates a `SOS` / `CRITICAL` incident.
2. Within one refresh the panel shows it: A1 Attention Required, first row,
   and the A8 badge increments.
3. The row carries bus, driver, trip, time and location.
4. A VIEWER sees everything and can do nothing — no acknowledge button exists
   for them.

**Deliberately not built:** desktop notifications, sound, and a modal that
seizes the screen. An operator watching a fleet does not need to be startled;
they need the row at the top, in red, on every screen's badge.

---

## J6 — Supervisor acknowledges

**SUPPORT.**

1. A8 → A9.
2. Reads the driver's own words. Views the evidence photograph inline.
3. **Acknowledge.** `POST /incidents/{id}/acknowledge`. Status becomes
   `ACKNOWLEDGED`, and the audit log records who and when.
4. Adds a note: "Called driver, he is safe, bus blocking the road."

Close is **absent** — that is `OPERATIONS`.

---

## J7 — Operations resolves and closes

**OPERATIONS.**

1. A9. **Resolve** — `POST /incidents/{id}/resolve` with what was done.
2. Then **Close** — `POST /incidents/{id}/close`.
3. Both are confirmed first, and both write audit entries.

If the backend refuses — wrong state, work outstanding — the **409 message is
shown verbatim**. The panel does not translate a refusal about safety.

---

## J8 — Breakdown → replacement vehicle

**SUPPORT dispatches, OPERATIONS approves.**

1. `BREAKDOWN` incident with a photograph.
2. The backend recommends a replacement: `ReplacementStatus::RECOMMENDED`.
3. A9 shows the recommendation with the candidate bus and its distance.
4. **OPERATIONS approves** → `APPROVED`.
5. **SUPPORT dispatches** → `DISPATCHED`, then marks **arrived** → `ARRIVED`.
6. Each transition is one endpoint, shown as one button, gated at its own level.

The two-level split is the backend's, not an invention: approving costs money,
dispatching does not.

---

## J9 — Failed inspection → maintenance

**SUPPORT.**

1. A driver fails a critical item with a photograph. The bus is not cleared.
2. A1 Attention Required: "Inspection failed — AP-39-…".
3. A11 shows today's failures. Opening one shows the failed items and the
   photographs the driver took.
4. **Open a ticket** — `POST /maintenance-tickets`, pre-filled with the bus,
   the failed item and the inspection reference.
5. Assign, schedule, start. Each is its own endpoint at `SUPPORT`.

---

## J10 — Vehicle returns to service

**OPERATIONS.**

1. A10, ticket `IN_PROGRESS`. **Complete** — `POST /…/complete` with work done
   and odometer.
2. The bus is still not `AVAILABLE`. Returning it is a second, deliberate act:
   `PATCH /buses/{id}/status` to `AVAILABLE`.
3. The panel presents this as one guided step with a confirmation naming the
   bus, because putting a vehicle back on the road is a safety decision.
4. If readiness still blocks it the backend refuses with 409, and the reasons
   are shown as they are written.

**There is no `return-to-service` endpoint.** Two calls, one intent, stated.

---

## J11 — Review the fleet

**VIEWER upwards.** A5 → filter by status → open A6 for readiness, inspections,
maintenance, documents and current trip in one place.

---

## J12 — Review drivers

**VIEWER upwards.** A7 → driver → licence and expiry, assigned bus, today's
trip, incident history. Licence data comes from the driver record; there is no
driver-documents endpoint (G0-1).

---

## J13 — Review students

**VIEWER upwards.** A12 → student → route, stop, pass, status.

Personal data. Reads here are recorded by the backend's data-access log, which
is why A16 exists and why the panel never bulk-fetches student detail to
populate a list it does not show.

---

## J14 — Generate a trip report

**VIEWER upwards.** A15 → Trips → date range → table and summary → "Download
this table" produces CSV **from the rows on screen**, in the browser.

Called "download this table", not "export", because there is no server-side
export and pretending otherwise would imply an authoritative extract (G1-3).

---

## J15 — Review an incident report

**VIEWER upwards.** A15 → Incidents → range → counts by type and severity, and
rows that link back into A9.

---

## J16 — Audit an operational action

**SUPER_ADMIN.**

1. "Who cancelled TR-014 yesterday?"
2. A16 → filter `action` and date → the entry names the actor, the table, the
   record, the before and after, and the IP.
3. `GET /data-access-logs` answers the different question of who *read* a
   student's file.

The two logs are never merged. One is about change, the other about access.

---

## J17 — Export a student's personal data

**SUPER_ADMIN.**

1. A16 → Subject access → choose the user → confirm.
2. `POST /users/{id}/subject-access-export`.
3. The confirmation names the person whose data is leaving the system, because
   this is the most consequential button in the panel.

---

## Journeys deliberately not specified

| Journey | Why not |
|---|---|
| Create a route / stop / schedule | Network planning. Post-MVP; reads only |
| Merge services (consolidation) | Post-MVP, and carries G3-1 |
| Review an attendance dispute | Post-MVP, and carries G3-1 |
| Board a student from the panel | The bus does this. An office that can board students remotely has broken its own evidence |
