# API contract

Every endpoint the panel uses. Taken from the router, not from a design.
Paths omit the `/api/v1` prefix.

## Envelope

Every response, success or failure:

```jsonc
{ "success": true, "message": "…", "data": {}, "code": 200 }
```

Paginated responses add:

```jsonc
"pagination": { "total": 142, "per_page": 20, "current_page": 1, "last_page": 8 }
```

`pagination.total` is how the dashboard counts things. **Never** count the rows
in `data` — that counts one page.

`per_page` is capped at **100** server-side. Any screen that would need more
than 100 rows needs pagination, not a bigger page.

## Status codes, and what the panel does with each

| Code | Meaning | Panel behaviour |
|---|---|---|
| 200 | Read or mutation succeeded | Render |
| 201 | Created | Render, toast the creation |
| 204 | No content | Treat as success, no body |
| 400 | Malformed request | Bug in the panel. Generic error + retry |
| 401 | No/expired/revoked token, or deactivated user | Attempt one refresh; if that fails, end the session and return to login |
| 403 | Authenticated, not permitted | **"You don't have permission to perform this action."** Never the server's text, never the path |
| 404 | Not found, or not visible to this actor | "That record is no longer available." Return to the list |
| 409 | Business-rule refusal | **Show `message` verbatim.** Read `errors` for detail. Never paraphrase |
| 422 | Validation | Map `errors` to fields; show the first field error if the form has no field |
| 429 | Rate limited | Back off, pause polling, say "Too many requests — retrying shortly" |
| 5xx | Server fault | "The server could not complete that." + retry. Never a stack trace |

**409 is the rule the driver app already follows and the panel inherits it.**
A refusal like "This bus is not cleared for service" is written for a human and
is more accurate than anything the panel could compose. Paraphrasing a safety
refusal is how somebody talks themselves past it.

## Authentication

| Method | Path | Notes |
|---|---|---|
| POST | `auth/login` | Public. `email`, `password`. Returns access + refresh tokens and the user |
| POST | `auth/refresh` | Public. Single-use refresh token; the old one is revoked |
| GET | `auth/me` | Confirms identity, **and carries the access level** the panel gates on |
| POST | `auth/logout` | This device |
| POST | `auth/logout-all` | Every device |
| POST | `auth/change-password` | Self-service |

`POST auth/register` exists and is public, but only ever creates a `STUDENT`:
the route has no authentication middleware, so `RegisterRequest::authorize()`
sees no user and refuses any other role. **The panel does not use it.** Account
creation is `POST /users`, `SUPER_ADMIN` only.

The panel must refuse to proceed if `auth/me` returns a user whose role is not
`ADMIN`. A driver's token is valid; a driver has no business here.

## Trips

| Method | Path | Query / body | Used by |
|---|---|---|---|
| GET | `trips` | `status`, `date`, `from`, `to`, `route_id`, `driver_id`, `bus_id`, `per_page` | A1, A2, A3 |
| GET | `trips/{id}` | — | A4 |
| GET | `trips/{id}/live` | — | A2, A4 |
| GET | `trips/{id}/eta` | **`stop_id` required for an admin** | A2, A4 |
| GET | `trips/{id}/stops/{stopId}/manifest` | — | A4 |
| GET | `trips/{id}/corrections` | — | A4 |
| POST | `trips/{id}/corrections` | correction payload | A4, ⚠ not level-gated |
| POST | `trips/{id}/cancel` | reason | A3, A4 — OPERATIONS |
| POST | `trips/{id}/reassign` | bus / driver | A4 — OPERATIONS |
| POST | `trips` | schedule payload | OPERATIONS |
| POST | `trips/generate` | date | OPERATIONS |

`GET /trips/{id}/eta` **requires `stop_id`** for an admin — it defaults only to
a student's own pickup stop. The panel passes the trip's next pending stop,
taken from the `live` response. Calling it without one is a 422, not a default.

`live` returns position with a server-computed `is_stale`, occupancy, delay and
per-stop state. `eta` returns `eta_at`, `minutes`, `basis`, `stops_away`,
`distance_metres` and `distance_is_estimate`.

**`distance_metres` is road distance from the routing provider.** The panel
renders it and never computes its own. `distance_is_estimate: true` renders
with a leading `~`, exactly as the driver app does.

## Fleet

| Method | Path | Query / body | Used by |
|---|---|---|---|
| GET | `buses` | `status`, `search`, `per_page` | A1, A5 |
| GET | `buses/{id}` | — | A6 |
| GET | `buses/{id}/service-readiness` | — | A1, A5, A6, A11 |
| GET | `buses/{id}/inspections` | — | A6, A11 |
| GET | `buses/{id}/documents` | — | A6 |
| GET | `fleet/documents/expiring` | — | A1, A5 |
| GET | `inspections/{id}` | — | A11 |
| GET | `inspections/checklist` | — | reference only |
| PATCH | `buses/{id}/status` | `status` | A6 — OPERATIONS |
| POST/PUT/DELETE | `buses`, `buses/{id}` | bus payload | OPERATIONS |
| POST/PUT/DELETE | `buses/{id}/documents` | document payload | OPERATIONS |

`BusStatus` is `AVAILABLE RUNNING MAINTENANCE BREAKDOWN OFFLINE`.
`InspectionOutcome` is `PASSED PASSED_WITH_DEFECTS FAILED`.

## People

| Method | Path | Query / body | Used by |
|---|---|---|---|
| GET | `drivers` | — | A1, A7 |
| GET | `drivers/{id}` | — | A7 |
| PATCH | `drivers/{id}/status` | `status` | A7 — policy: admin or self |
| POST/PUT/DELETE | `drivers`, `drivers/{id}` | driver payload | OPERATIONS |
| POST/DELETE | `drivers/{id}/assign-bus` | `bus_id` | OPERATIONS |
| GET | `students` | — | A12 |
| GET | `students/{id}` | — | A12 |
| POST/DELETE | `students/{id}/assign-transport` | route, stop, pass | OPERATIONS |
| PATCH | `students/{id}/status` | `status` | OPERATIONS |

`DriverStatus` is `AVAILABLE ON_TRIP LEAVE OFF_DUTY`.
`StudentStatus` is `ACTIVE INACTIVE SUSPENDED`.

## Safety

| Method | Path | Query / body | Used by |
|---|---|---|---|
| GET | `incidents` | `status`, `type`, `bus_id`, `per_page` | A1, A8 |
| GET | `incidents/{id}` | — | A9 |
| GET | `incidents/types` | — | A8 filters |
| POST | `incidents/{id}/acknowledge` | — | A9 — SUPPORT |
| POST | `incidents/{id}/resolve` | resolution | A9 — SUPPORT |
| POST | `incidents/{id}/close` | — | A9 — OPERATIONS |
| POST | `incidents/{id}/notes` | note | A9 |
| POST | `incidents/{id}/cancel` | reason | A9 |
| GET | `evidence/{id}` | — | A9 |
| GET | `evidence/categories` | — | reference |

`IncidentStatus`: `REPORTED ACKNOWLEDGED IN_PROGRESS ESCALATED RESOLVED CLOSED`.
`IncidentSeverity`: `LOW MEDIUM HIGH CRITICAL`.
`IncidentType`: `SOS ACCIDENT MEDICAL SECURITY BREAKDOWN FLAT_TYRE ENGINE_FAULT
BRAKE_FAULT FUEL DIVERSION CONGESTION WEATHER PASSENGER_CONDUCT TRACKING_LOST`.

**No `severity` or date filter exists** — see G1-2.

## Maintenance and recovery

| Method | Path | Query / body | Used by |
|---|---|---|---|
| GET | `maintenance-tickets` | `status`, `priority`, `bus_id`, `per_page` | A1, A10 |
| GET | `maintenance-tickets/{id}` | — | A10 |
| POST | `maintenance-tickets` | ticket payload | A10 — SUPPORT |
| POST | `maintenance-tickets/{id}/assign` | mechanic / workshop | A10 — SUPPORT |
| POST | `maintenance-tickets/{id}/schedule` | date | A10 — SUPPORT |
| POST | `maintenance-tickets/{id}/start` | odometer | A10 — SUPPORT |
| POST | `maintenance-tickets/{id}/complete` | work done, odometer | A10 — OPERATIONS |
| POST | `maintenance-tickets/{id}/cancel` | reason | A10 — OPERATIONS |
| GET | `preventive-maintenance` | — | A10 |
| GET | `replacements`, `replacements/{id}` | — | A9 |
| POST | `replacements/{id}/approve` \| `reject` | — | A9 — OPERATIONS |
| POST | `replacements/{id}/dispatch` \| `arrived` | — | A9 — SUPPORT |

`MaintenanceStatus`: `OPEN SCHEDULED IN_PROGRESS COMPLETED CANCELLED`.
`MaintenancePriority`: `LOW MEDIUM HIGH URGENT`.
`ReplacementStatus`: `RECOMMENDED APPROVED REJECTED DISPATCHED ARRIVED COMPLETED`.

There is no separate "return to service" endpoint. A bus returns to service by
completing its ticket and then `PATCH /buses/{id}/status` to `AVAILABLE`, which
the backend gates on readiness. A10 presents that as one guided step, two calls.

## Communication

| Method | Path | Used by |
|---|---|---|
| GET | `notifications`, `notifications/unread-count` | top bar, A13 |
| PATCH | `notifications/{id}/read` \| `unread` | A13 |
| POST | `notifications/read-all` | A13 |
| GET | `notification-log`, `notification-log/health` | A13 |
| POST | `notification-log/{id}/resend` | A13 — ⚠ not level-gated |
| GET | `announcements`, `announcements/{id}` | A14 |
| POST/PUT | `announcements`, `announcements/{id}` | A14 — OPERATIONS |
| POST | `announcements/{id}/publish` \| `withdraw` | A14 — OPERATIONS |

`AnnouncementAudience`: `ALL STUDENTS DRIVERS ADMINS`.
`DeliveryStatus`: `QUEUED SENT DELIVERED RETRYING PERMANENTLY_FAILED SUPPRESSED`.
`NotificationPriority`: `CRITICAL STANDARD` — only two, see G0-2.

## Reports

All six are `GET`, `ADMIN`, no access level, and accept `date`, `from`, `to`.

`reports/trips` · `reports/attendance` · `reports/fleet` · `reports/incidents` ·
`reports/maintenance` · `reports/occupancy`

No export endpoint exists — see G1-3.

## Administration — SUPER_ADMIN only

| Method | Path | Used by |
|---|---|---|
| GET | `audit-logs` (`action`, `date`, `from`, `to`, `per_page`) | A16 |
| GET | `audit-logs/{id}` | A16 |
| GET | `data-access-logs` | A16 |
| GET | `retention-runs` | A16 |
| POST | `users/{id}/subject-access-export` | A16 |
| GET | `users`, `users/{id}` | Administration |
| POST | `users` | Administration |
| PATCH | `users/{id}/status` | Administration |

## Endpoint usage audit

**Used by the panel: 63 of 158**, counted mechanically from the screen →
endpoint table in `02`. A few grouped rows (`assign`\|`schedule`\|`start`) cover
siblings the count does not reach individually, so the true figure is a little
higher; 63 is the number that can be reproduced by parsing the table.

**Not used, and why:**

| Group | Count | Why not |
|---|---|---|
| Driver-app write path — `positions`, `board`, `alight`, `arrive`, `skip`, `left-behind`, `start`, `complete`, `inspections` (POST) | 9 | The bus reports these. The office watches; it does not board students from a laptop |
| `notification-devices/*`, `notification-preferences` | 6 | Per-device push registration. The panel is a browser and registers no device for the MVP |
| `geo/geocode`, `geo/reverse`, `geo/places` | 3 | Route-building tools. Out of MVP scope — the panel does not create routes |
| `routes` and `schedules` writes, `service-calendar` writes | 12 | Network planning. Read-only in the MVP; see 11-implementation-guide |
| `consolidations/*` | 8 | Service merging is a post-MVP workflow |
| `evidence` POST, `evidence/categories` | 2 | The panel views evidence, it does not capture it |
| `auth/register` | 1 | Students only; account creation is `POST /users` |
| Misc self-service — `auth/change-password`, `notifications/{id}` DELETE | 2 | Kept for the account menu, not a screen |

**Important endpoints with no destination screen: none.** Every read endpoint
that describes fleet, trip, safety, maintenance, people or audit state is
mapped in `02-screen-api-matrix.md`.
