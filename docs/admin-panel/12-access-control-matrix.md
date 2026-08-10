# Access control matrix

Generated from `php artisan route:list` against the frozen backend and from
`app/Policies`. This records what the server **enforces**, not what the product
would like. Where the two differ it says so.

## How authorization actually works

Two independent axes, checked in this order:

```text
AuthenticateRequest      is there a valid, unexpired token for an active user?
        ↓
RoleAuthorize:ADMIN      is this user's UserRole one of the listed roles?
        ↓
RequireAccessLevel:X     does admins.access_level meet X, by atLeast()?
        ↓
Policy                   may this specific user touch this specific row?
```

The critical fact, and the one that shapes this whole document:

> **No policy consults the access level.** Every policy method asks
> `isAdmin()`. The access level is enforced *only* by `RequireAccessLevel`
> middleware on the route. A mutating route without that middleware admits
> every admin, including a VIEWER.

`AccessLevel::atLeast()` makes the levels a ladder, so `OPERATIONS` satisfies a
`SUPPORT` gate and `SUPER_ADMIN` satisfies everything.

```text
VIEWER  <  SUPPORT  <  OPERATIONS  <  SUPER_ADMIN
```

## Product terminology → backend

| Product concept | Access level |
|---|---|
| Read-only oversight | `VIEWER` |
| Supervisor | `SUPPORT` |
| Transport Head | `OPERATIONS` |
| System administrator | `SUPER_ADMIN` |

No new role is created. The panel refuses to log in any user whose
`UserRole` is not `ADMIN`.

## Reading the matrix

- **✓** — server-enforced allow
- **—** — server-enforced deny (403)
- **⚠** — **UI-hidden but NOT server-enforced.** The panel does not offer it,
  and the server would accept it from anyone with a token. See G3-1.

## Navigation and read access

Every route below is authenticated-only or `RoleAuthorize:ADMIN` with no level
gate, so all four levels can read them.

| Capability | Endpoint | VIEWER | SUPPORT | OPERATIONS | SUPER_ADMIN |
|---|---|:--:|:--:|:--:|:--:|
| Dashboard | composed, see A1 | ✓ | ✓ | ✓ | ✓ |
| List trips | `GET /trips` | ✓ | ✓ | ✓ | ✓ |
| Trip detail | `GET /trips/{id}` | ✓ | ✓ | ✓ | ✓ |
| Live position | `GET /trips/{id}/live` | ✓ | ✓ | ✓ | ✓ |
| ETA + road distance | `GET /trips/{id}/eta` | ✓ | ✓ | ✓ | ✓ |
| Stop manifest | `GET /trips/{id}/stops/{stopId}/manifest` | ✓ | ✓ | ✓ | ✓ |
| Trip corrections (read) | `GET /trips/{id}/corrections` | ✓ | ✓ | ✓ | ✓ |
| List buses | `GET /buses` | ✓ | ✓ | ✓ | ✓ |
| Bus detail | `GET /buses/{id}` | ✓ | ✓ | ✓ | ✓ |
| Service readiness | `GET /buses/{id}/service-readiness` | ✓ | ✓ | ✓ | ✓ |
| Inspection history | `GET /buses/{id}/inspections` | ✓ | ✓ | ✓ | ✓ |
| Inspection detail | `GET /inspections/{id}` | ✓ | ✓ | ✓ | ✓ |
| Bus documents | `GET /buses/{id}/documents` | ✓ | ✓ | ✓ | ✓ |
| Expiring documents | `GET /fleet/documents/expiring` | ✓ | ✓ | ✓ | ✓ |
| List drivers | `GET /drivers` | ✓ | ✓ | ✓ | ✓ |
| Driver detail | `GET /drivers/{id}` | ✓ | ✓ | ✓ | ✓ |
| List students | `GET /students` | ✓ | ✓ | ✓ | ✓ |
| Student detail | `GET /students/{id}` | ✓ | ✓ | ✓ | ✓ |
| List incidents | `GET /incidents` | ✓ | ✓ | ✓ | ✓ |
| Incident detail | `GET /incidents/{id}` | ✓ | ✓ | ✓ | ✓ |
| Incident types | `GET /incidents/types` | ✓ | ✓ | ✓ | ✓ |
| Evidence detail | `GET /evidence/{id}` | ✓ | ✓ | ✓ | ✓ |
| Maintenance tickets | `GET /maintenance-tickets` | ✓ | ✓ | ✓ | ✓ |
| Preventive maintenance | `GET /preventive-maintenance` | ✓ | ✓ | ✓ | ✓ |
| Replacements | `GET /replacements` | ✓ | ✓ | ✓ | ✓ |
| Consolidations | `GET /consolidations` | ✓ | ✓ | ✓ | ✓ |
| Routes and stops | `GET /routes`, `/routes/{id}/stops` | ✓ | ✓ | ✓ | ✓ |
| Schedules | `GET /schedules` | ✓ | ✓ | ✓ | ✓ |
| Service calendar | `GET /service-calendar` | ✓ | ✓ | ✓ | ✓ |
| Announcements | `GET /announcements` | ✓ | ✓ | ✓ | ✓ |
| Notification log | `GET /notification-log` | ✓ | ✓ | ✓ | ✓ |
| Notification health | `GET /notification-log/health` | ✓ | ✓ | ✓ | ✓ |
| Attendance discrepancies | `GET /attendance-discrepancies` | ✓ | ✓ | ✓ | ✓ |
| All six reports | `GET /reports/trips`, `/reports/attendance`, `/reports/fleet`, `/reports/incidents`, `/reports/maintenance`, `/reports/occupancy` | ✓ | ✓ | ✓ | ✓ |
| Geocoding / places / status | `GET /geo/geocode`, `/geo/reverse`, `/geo/places`, `/geo/status` | — | — | ✓ | ✓ |

Note the last row: the `geo` endpoints are gated at `OPERATIONS`. They are
route-building tools, not map tools — the panel's map needs none of them.

## Operational mutations

| Capability | Endpoint | VIEWER | SUPPORT | OPERATIONS | SUPER_ADMIN |
|---|---|:--:|:--:|:--:|:--:|
| Acknowledge incident | `POST /incidents/{id}/acknowledge` | — | ✓ | ✓ | ✓ |
| Resolve incident | `POST /incidents/{id}/resolve` | — | ✓ | ✓ | ✓ |
| Close incident | `POST /incidents/{id}/close` | — | — | ✓ | ✓ |
| Add incident note | `POST /incidents/{id}/notes` | ⚠ | ✓ | ✓ | ✓ |
| Cancel incident | `POST /incidents/{id}/cancel` | ⚠ | ✓ | ✓ | ✓ |
| Raise incident | `POST /incidents` | ⚠ | ✓ | ✓ | ✓ |
| Dispatch replacement | `POST /replacements/{id}/dispatch` | — | ✓ | ✓ | ✓ |
| Replacement arrived | `POST /replacements/{id}/arrived` | — | ✓ | ✓ | ✓ |
| Approve replacement | `POST /replacements/{id}/approve` | — | — | ✓ | ✓ |
| Reject replacement | `POST /replacements/{id}/reject` | — | — | ✓ | ✓ |
| Open maintenance ticket | `POST /maintenance-tickets` | — | ✓ | ✓ | ✓ |
| Assign ticket | `POST /maintenance-tickets/{id}/assign` | — | ✓ | ✓ | ✓ |
| Schedule ticket | `POST /maintenance-tickets/{id}/schedule` | — | ✓ | ✓ | ✓ |
| Start work | `POST /maintenance-tickets/{id}/start` | — | ✓ | ✓ | ✓ |
| Complete work | `POST /maintenance-tickets/{id}/complete` | — | — | ✓ | ✓ |
| Cancel ticket | `POST /maintenance-tickets/{id}/cancel` | — | — | ✓ | ✓ |
| Cancel trip | `POST /trips/{id}/cancel` | — | — | ✓ | ✓ |
| Reassign trip | `POST /trips/{id}/reassign` | — | — | ✓ | ✓ |
| Create trip | `POST /trips` | — | — | ✓ | ✓ |
| Generate day's trips | `POST /trips/generate` | — | — | ✓ | ✓ |
| Change bus status | `PATCH /buses/{id}/status` | — | — | ✓ | ✓ |
| Create / edit / delete bus | `POST /buses`, `PUT,DELETE /buses/{id}` | — | — | ✓ | ✓ |
| Add bus document | `POST /buses/{id}/documents` | — | — | ✓ | ✓ |
| Edit / delete bus document | `PUT,DELETE /buses/{busId}/documents/{documentId}` | — | — | ✓ | ✓ |
| Create / edit / delete driver | `POST /drivers`, `PUT,DELETE /drivers/{id}` | — | — | ✓ | ✓ |
| Assign / unassign bus | `POST,DELETE /drivers/{id}/assign-bus` | — | — | ✓ | ✓ |
| Manage students | `POST /students`, `DELETE /students/{id}`, `POST,DELETE /students/{id}/assign-transport`, `PATCH /students/{id}/status` | — | — | ✓ | ✓ |
| Manage routes and stops | `POST /routes`, `PUT,DELETE /routes/{id}`, `PATCH /routes/{id}/status`, `POST /routes/{id}/stops`, `PUT,DELETE /routes/{routeId}/stops/{stopId}` | — | — | ✓ | ✓ |
| Manage schedules | `POST /schedules`, `PUT,DELETE /schedules/{id}`, `PATCH /schedules/{id}/status` | — | — | ✓ | ✓ |
| Manage service calendar | `POST /service-calendar`, `DELETE /service-calendar/{id}` | — | — | ✓ | ✓ |
| Create announcement | `POST /announcements` | — | — | ✓ | ✓ |
| Edit announcement | `PUT /announcements/{id}` | — | — | ✓ | ✓ |
| Publish / withdraw announcement | `POST /announcements/{id}/publish,withdraw` | — | — | ✓ | ✓ |
| Upload evidence | `POST /evidence` | ⚠ | ✓ | ✓ | ✓ |

## Administration

| Capability | Endpoint | VIEWER | SUPPORT | OPERATIONS | SUPER_ADMIN |
|---|---|:--:|:--:|:--:|:--:|
| Audit log | `GET /audit-logs`, `/audit-logs/{id}` | — | — | — | ✓ |
| Data access log | `GET /data-access-logs` | — | — | — | ✓ |
| Retention runs | `GET /retention-runs` | — | — | — | ✓ |
| Subject access export | `POST /users/{id}/subject-access-export` | — | — | — | ✓ |
| Create account | `POST /users` | — | — | — | ✓ |
| Activate / deactivate account | `PATCH /users/{id}/status` | — | — | — | ✓ |
| List users | `GET /users` | ✓ | ✓ | ✓ | ✓ |

`GET /users` carries `RoleAuthorize:ADMIN` with no level gate, so every admin
can list accounts. The panel exposes the list only under Administration and
only to `SUPER_ADMIN`, because a VIEWER has no use for it — but this is a
**UI choice, not a server rule**.

## The eight that are not enforced

Repeated here so the implementer cannot miss them. Every one carries
`RoleAuthorize:ADMIN` and **no** `RequireAccessLevel`, and its policy asks only
`isAdmin()`. Verified by probe; see G3-1.

| Endpoint | Panel shows it to | Server accepts it from |
|---|---|---|
| `POST /trips/{id}/corrections` | OPERATIONS+ | any admin |
| `POST /attendance-discrepancies/{id}/review` | SUPPORT+ | any admin |
| `POST /consolidations` | OPERATIONS+ | any admin |
| `POST /consolidations/{id}/approve` | OPERATIONS+ | any admin |
| `POST /consolidations/{id}/execute` | OPERATIONS+ | any admin |
| `POST /consolidations/{id}/reject` | OPERATIONS+ | any admin |
| `POST /preventive-maintenance` | OPERATIONS+ | any admin |
| `DELETE /preventive-maintenance/{id}` | OPERATIONS+ | any admin |
| `POST /notification-log/{id}/resend` | SUPPORT+ | any admin |

## Frontend rules

1. The panel **never decides** whether an action is permitted. It decides
   whether to *offer* it. The server's 403 is the decision.
2. Capability gating is computed once, from `auth/me`'s access level, into a
   `can(capability)` helper. There is no permission logic scattered in screens.
3. A hidden control and a disabled control are different. Actions the level can
   never perform are **absent**; actions blocked by *state* (resolving an
   already-resolved incident) are **disabled with a reason**.
4. A 403 that arrives anyway is a bug in the panel's gating, and is reported as
   "You don't have permission to perform this action." — never with the
   backend's internal message, and never with the endpoint path.
