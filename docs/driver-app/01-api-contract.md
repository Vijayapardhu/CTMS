# Driver App — API Contract

Built against `v1.0.0-backend-freeze`. Every endpoint below was extracted from the running router and its scope verified by test, not read off a design document.

---

## Routable is not reachable

68 endpoints pass the driver's *route* gate. Fewer are actually usable, because the route gate is deliberately coarse and record-level scope belongs to a policy.

Verified in `tests/Feature/Hardening/DriverScopeTest.php` (16 tests):

| Endpoint | Route says | Policy says |
|---|---|---|
| `GET /users/{id}` | reachable | **own record only** |
| `PUT /users/{id}` | reachable | **own record only**, and `role` / `is_active` / `is_system` are ignored |
| `GET /students/{id}` | reachable | **403 always** — a driver gets the stop manifest, not a child's file |
| `PUT /students/{id}` | reachable | **403 always** |
| `GET /drivers/{id}` | reachable | **own record only** |
| `PATCH /drivers/{id}/status` | reachable | **own record only** |
| `PUT /drivers/{id}` | reachable | **403 always** — a licence expiry the holder can edit is not a compliance record |
| `POST /drivers/{id}/assign-bus` | reachable | **403 always** |

**Do not build UI against the routable list.** Build against the workflows below.

---

## The nine workflows

### 1 · Authentication

| Method | Path | Notes |
|---|---|---|
| `POST` | `/auth/login` | `email`, `password`. Throttled **5/min per email**, 20/min per IP |
| `POST` | `/auth/refresh` | Throttled 120/min. Carries no email — separate bucket by design |
| `POST` | `/auth/logout` | Current device |
| `POST` | `/auth/logout-all` | Every device |
| `POST` | `/auth/change-password` | Throttled **5/min** |
| `GET` | `/auth/me` | Identity + profile. `data.profile.access_level` is null for a driver |

Login returns `access_token` and `refresh_token`. A **deactivated user is rejected on every request**, not only at login — so a 401 mid-trip may mean deactivation, not expiry. Refresh once; if refresh also fails, go to Session Expired.

Login failures never reveal whether the email exists. Do not write UI copy that implies otherwise.

### 2 · Today's trip

| Method | Path | Notes |
|---|---|---|
| `GET` | `/trips` | Scoped to this driver automatically. Filter `?status=`, `?date=` |
| `GET` | `/trips/{id}` | Own trips only |
| `GET` | `/trips/{id}/live` | Position, staleness, occupancy, delay, per-stop state |
| `GET` | `/trips/{id}/eta` | Per-stop estimates with provenance |

`/live` response — this drives the running-trip screen and the map:

```jsonc
{
  "trip_id": "…", "status": "RUNNING",
  "position": {
    "latitude": 17.45, "longitude": 78.45,
    "recorded_at": "…", "age_seconds": 8,
    "is_stale": false          // > 120s. The client must never show stale as live
  },
  "occupancy": { "occupied": 23, "capacity": 40 },
  "delay_minutes": 6,
  "stops": [{ "stop_id": "…", "stop_name": "…", "sequence_number": 1,
              "state": "DEPARTED", "eta_at": "…", "arrived_at": "…" }]
}
```

`is_stale` is computed server-side. **Render it.** A driver seeing a confident marker over a position eight minutes old will trust it.

### 3 · Trip lifecycle

| Method | Path | Notes |
|---|---|---|
| `POST` | `/trips/{id}/start` | The composite safety gate |
| `POST` | `/trips/{id}/complete` | `odometer_reading` |

`start` returns **409** with every blocking reason at once, not the first:

```jsonc
{ "success": false,
  "message": "This bus is not cleared for service: …",
  "errors": { "reasons": [
     "No pre-trip inspection has been completed today.",
     "Insurance is missing or expired." ] },
  "code": 409 }
```

Render `errors.reasons` as a list. Other 409s from this endpoint: outside the start window, licence expired, driver already on an active trip, driver stood down after a critical incident (BR-109), bus past its preventive-maintenance grace period (BR-366).

### 4 · Pre-trip inspection

| Method | Path | Notes |
|---|---|---|
| `GET` | `/inspections/checklist` | Server-driven. **Never hard-code the item list** |
| `GET` | `/buses/{id}/service-readiness` | `{ cleared, reasons[], inspection }` |
| `POST` | `/buses/{id}/inspections` | Full checklist, all items |
| `GET` | `/inspections/{id}` | |
| `GET` | `/buses/{id}/inspections` | History |

Submission shape:

```jsonc
{ "odometer_reading": 45120,
  "notes": null,
  "items": [
    { "item": "BRAKES", "passed": false,
      "notes": "Pedal travel excessive.",
      "evidence_id": "…" },     // REQUIRED when a safety-critical item fails
    { "item": "LIGHTS", "passed": true }
  ] }
```

Rules the UI must respect:
- **Every** checklist item must be present. A partial submission is 422.
- A failed **safety-critical** item requires `evidence_id`. Field error lands on `items.{index}.evidence_id`.
- The odometer must be ≥ the bus's recorded total (BR-061). A lower value is 409, not 422.
- The **outcome is decided server-side** — `PASSED`, `PASSED_WITH_DEFECTS`, `FAILED`. Never compute it in the app.
- A `FAILED` outcome takes the bus off the road immediately and opens a maintenance ticket. Show that consequence *before* submitting.

### 5 · Live tracking

| Method | Path | Notes |
|---|---|---|
| `POST` | `/trips/{id}/positions` | Throttled **60/min** |
| `POST` | `/trips/{id}/stops/{stopId}/arrive` | Manual fallback when geofence misses |
| `POST` | `/trips/{id}/stops/{stopId}/skip` | `reason` required, min 5 chars |

Position payload:

```jsonc
{ "latitude": 17.45, "longitude": 78.45,
  "accuracy_meters": 8, "speed_kmh": 32, "heading": 145,
  "altitude_meters": 540,
  "recorded_at": "2026-08-07T09:14:03+05:30",   // device clock, honoured
  "idempotency_key": "trip-…-seq-0042" }        // required for offline replay
```

Constraints that will bite:
- **60/min ceiling.** Sample at 5–10s (6–12/min) and you have headroom for replay bursts. A replay must be throttled client-side or it will 429.
- `latitude` runs through a **service-area rule**. A position outside the configured area is **422**, not accepted. Buffer and surface it as "outside service area", never as a generic failure.
- The server runs a **plausibility gate**: an implausible jump is rejected with 409 and never stored. This is correct behaviour, not an error to retry blindly.
- `idempotency_key` is what makes replay safe. Generate it once per fix, never per attempt.
- Positions are only accepted for a `RUNNING` trip. Any other status is 409 — stop the stream.

### 6 · Boarding

| Method | Path | Notes |
|---|---|---|
| `POST` | `/trips/{id}/board` | `student_id` optional — omit for anonymous headcount |
| `POST` | `/trips/{id}/alight` | Same shape |
| `POST` | `/trips/{id}/left-behind` | `student_ids[]` required, 1–100 |
| `GET` | `/trips/{id}/stops/{stopId}/manifest` | Who is expected here |

Both `board` and `alight` accept `student_id`, `route_stop_id`, `idempotency_key` — **all optional**. That is what makes one-tap counting possible: the driver presses `+1` and nothing else is required.

Boarding refuses at capacity (409). Alighting below zero refuses (409) — a negative headcount is a data error, not a bus with minus one passenger.

### 7 · Incidents

| Method | Path | Notes |
|---|---|---|
| `GET` | `/incidents/types` | Server-driven catalogue with `requires_photo` and `class` |
| `POST` | `/incidents` | |
| `GET` | `/incidents` | **Own reports only** |
| `GET` | `/incidents/{id}` | Own only |
| `POST` | `/incidents/{id}/notes` | Follow-up. The original is immutable (BR-357) |
| `POST` | `/incidents/{id}/cancel` | **Own report only** — a false alarm is withdrawn, never erased (BR-355) |

Validation gets **lighter as severity rises**. This is the single most important UI fact in this document:

| Class | Example | Requires description | Requires photo |
|---|---|---|---|
| Life safety | `SOS`, `MEDICAL`, `ACCIDENT`, `SECURITY` | **No** | **No** |
| Operational | `BREAKDOWN`, `FLAT_TYRE`, `ENGINE_FAULT`, `BRAKE_FAULT`, `FUEL` | Yes | **Yes** |
| Service | `CONGESTION`, `DIVERSION`, `WEATHER`, `PASSENGER_CONDUCT` | Yes | No |

An SOS needs `incident_type` and nothing else. Build the SOS path so it cannot be made to ask for more.

`TRACKING_LOST` is system-raised and **rejected from a client** (422). It will not appear in `/incidents/types`.

Offline fields: `reported_at` (device clock, preserved) and `idempotency_key` (replay absorbed, returns the original).

### 8 · Evidence

| Method | Path | Notes |
|---|---|---|
| `GET` | `/evidence/categories` | Limits and accepted types, per category |
| `POST` | `/evidence` | multipart: `file`, `category` |
| `GET` | `/evidence/{id}` | Authorised download, `Content-Disposition: attachment` |

Upload returns an **id, never a URL**:

```jsonc
{ "id": "…", "category": "INCIDENT_PHOTO", "mime_type": "image/jpeg",
  "size_bytes": 184320, "checksum": "…",
  "download_path": "/api/v1/evidence/…" }
```

Then cite the id as `evidence_id`. Rules:
- One file, one record. Re-citing an attached file is **409**.
- A driver may only cite **their own** upload — 409 otherwise.
- Category must match the use — an `INCIDENT_PHOTO` cited on an inspection is 409.
- MIME is sniffed from the bytes. A renamed file is rejected regardless of extension.
- Photo categories: `image/jpeg|png|heic|webp`, ceiling from `/evidence/categories` (default 8 MB).
- An upload never attached is **swept after 48 hours**. Upload late, not early.

### 9 · Notifications and profile

| Method | Path | Notes |
|---|---|---|
| `GET` | `/notifications` | Own only |
| `GET` | `/notifications/unread-count` | Badge |
| `PATCH` | `/notifications/{id}/read` · `/unread` | |
| `POST` | `/notifications/read-all` | |
| `DELETE` | `/notifications/{id}` | |
| `GET`/`PUT` | `/notification-preferences` | Safety categories are **locked** — render as locked with the reason |
| `GET`/`POST`/`DELETE` | `/notification-devices` | FCM registration |
| `GET` | `/announcements` · `/announcements/{id}` | Scoped to the driver audience |
| `GET`/`PUT` | `/users/{id}` | Own only |
| `GET` | `/drivers/{id}` · `PATCH` `/drivers/{id}/status` | Own only |
| `GET` | `/maintenance-tickets` | **Own assigned bus only** — why it is off the road |
| `GET` | `/buses/{id}/service-readiness` · `/documents` | |
| `GET` | `/routes` · `/routes/{id}/stops` · `/schedules` · `/service-calendar` | Reference data, cacheable |

---

## Screen → API map

No screen exists without an endpoint. No endpoint is unused.

```
Splash              GET  /auth/me

Login               POST /auth/login

Dashboard (Trip)    GET  /auth/me
                    GET  /trips?date=today
                    GET  /notifications/unread-count
                    GET  /buses/{id}/service-readiness

Pre-trip Inspection GET  /inspections/checklist
                    GET  /buses/{id}/service-readiness
                    POST /evidence                  (per failed critical item)
                    POST /buses/{id}/inspections

Start Trip          POST /trips/{id}/start

Trip Running        GET  /trips/{id}/live           (poll 10s / on resume)
                    POST /trips/{id}/positions      (5–10s)
                    GET  /trips/{id}/eta

Map                 GET  /trips/{id}/live
                    GET  /routes/{id}/stops         (cached)

Stop Details        GET  /trips/{id}/stops/{stopId}/manifest
                    POST /trips/{id}/stops/{stopId}/arrive
                    POST /trips/{id}/stops/{stopId}/skip
                    POST /trips/{id}/left-behind

Boarding            POST /trips/{id}/board
                    POST /trips/{id}/alight

SOS                 GET  /incidents/types           (cached at login)
                    POST /incidents

Breakdown           GET  /incidents/types
                    POST /evidence
                    POST /incidents

Incident Detail     GET  /incidents/{id}
                    POST /incidents/{id}/notes
                    POST /incidents/{id}/cancel

End Trip            POST /trips/{id}/complete

Trip Summary        GET  /trips/{id}
                    GET  /trips/{id}/live

History             GET  /trips?status=COMPLETED

Alerts              GET  /notifications
                    PATCH /notifications/{id}/read
                    GET  /announcements

Me                  GET  /auth/me
                    GET  /drivers/{id}
                    PATCH /drivers/{id}/status
                    GET  /maintenance-tickets?bus_id=
                    GET  /notification-preferences
```

---

## Response envelope

Every response, success or failure:

```jsonc
{ "success": true, "message": "…", "data": {…}, "code": 200 }
{ "success": false, "message": "…", "data": null, "errors": {…}, "code": 422 }
```

Paginated responses add `pagination: { total, per_page, current_page, last_page }`. `per_page` is capped at 100.

### Status codes and what the UI does

| Code | Meaning | UI response |
|---|---|---|
| 200 / 201 | Done | Proceed |
| 204 | Done, nothing to show | Proceed |
| **401** | Not authenticated, or **deactivated** | Refresh once; then Session Expired |
| **403** | Authenticated, not permitted | Explain; never retry |
| 404 | Gone or never existed | Empty state, not an error page |
| **409** | State conflict / business rule | **The message is written for the driver — show it verbatim.** Read `errors` for detail |
| **422** | Validation | Map `errors.{field}` onto inputs |
| **429** | Throttled | Back off; never a visible error during a trip |
| 500 | Server fault | Generic message. The body carries no internals by design (BR-511) |

**409 is the most important code in this app.** It is how every safety rule refuses, and the messages are already written in the driver's language ("This bus has 15 seats but 30 passengers need transferring"). Surface them; do not paraphrase.
