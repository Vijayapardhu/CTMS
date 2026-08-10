# Screen → API matrix

Both directions. Every screen's data has a source; every mapped endpoint has a
destination.

## Information architecture

Verified against the router. Sections the backend cannot fill are absent.

```text
Dashboard                        A1

Operations
├── Live Operations              A2
├── Trips                        A3
└── Routes                       A17  (read-only, MVP-if-cheap)

Fleet
├── Buses                        A5
├── Drivers                      A7
├── Inspections                  A11  (today's failures — see G2-2)
└── Maintenance                  A10

Safety
└── Incidents                    A8

People
└── Students                     A12

Communication
├── Alerts                       A13
└── Announcements                A14

Reports                          A15

Administration                   SUPER_ADMIN only
├── Audit Log                    A16
├── Data Access Log              A16
└── Accounts                     A18
```

Changes from the proposed structure, with reasons:

- **Evidence is not a section.** There is no endpoint that lists evidence;
  `GET /evidence/{id}` is by id only. Evidence appears where it was cited —
  inside an incident (A9) and an inspection (A11).
- **Drivers appears once, under Fleet.** The proposal listed it under both Fleet
  and People. One screen, one place; a driver is fleet capacity, and duplicating
  navigation teaches people the app has two of something it has one of.
- **Routes is read-only and marked MVP-if-cheap.** Every write is `OPERATIONS`
  and network planning is not part of the demo story.
- **System/Accounts split from Audit.** They are different jobs at the same
  access level and combining them buries the audit trail behind user admin.

## Screen → endpoint

| Screen | Endpoint | Purpose | R/W |
|---|---|---|---|
| A1 Dashboard | `GET /trips?date=today` | Trip counts by status | READ |
| A1 | `GET /buses` | Fleet counts by status | READ |
| A1 | `GET /incidents?status=REPORTED` | Open incident count + top 5 | READ |
| A1 | `GET /maintenance-tickets?status=OPEN` | Vehicles in the workshop | READ |
| A1 | `GET /fleet/documents/expiring` | Compliance attention | READ |
| A1 | `GET /buses/{id}/service-readiness` | Per blocked bus only | READ |
| A2 Live Operations | `GET /trips?status=RUNNING&date=today` | The tracked set | READ |
| A2 | `GET /trips/{id}/live` | Position, staleness, stop state | READ |
| A2 | `GET /trips/{id}/eta?stop_id=` | ETA + road distance, selected trip only | READ |
| A2 | `GET /routes/{id}/stops` | Stop geometry, cached per route | READ |
| A3 Trips | `GET /trips` + filters | The operational table | READ |
| A3 | `GET /routes`, `GET /drivers`, `GET /buses` | Filter option lists | READ |
| A4 Trip Details | `GET /trips/{id}` | Header and assignment | READ |
| A4 | `GET /trips/{id}/live` | Stops, occupancy, delay | READ |
| A4 | `GET /trips/{id}/eta?stop_id=` | Next-stop estimate | READ |
| A4 | `GET /trips/{id}/stops/{stopId}/manifest` | Who boarded where | READ |
| A4 | `GET /trips/{id}/corrections` | Correction history | READ |
| A4 | `GET /incidents?bus_id=` | Incidents on this bus | READ |
| A4 | `POST /trips/{id}/cancel` | Cancel — OPERATIONS | WRITE |
| A4 | `POST /trips/{id}/reassign` | Reassign — OPERATIONS | WRITE |
| A4 | `POST /trips/{id}/corrections` | Correct a closed record | WRITE |
| A5 Fleet | `GET /buses` + `status`, `search` | The fleet table | READ |
| A5 | `GET /fleet/documents/expiring` | Document column badge | READ |
| A6 Bus Details | `GET /buses/{id}` | Overview | READ |
| A6 | `GET /buses/{id}/service-readiness` | Cleared + reasons | READ |
| A6 | `GET /buses/{id}/inspections` | Inspection history | READ |
| A6 | `GET /buses/{id}/documents` | Statutory documents | READ |
| A6 | `GET /maintenance-tickets?bus_id=` | Workshop history | READ |
| A6 | `GET /incidents?bus_id=` | Incident history | READ |
| A6 | `GET /trips?bus_id=&date=today` | What it is doing now | READ |
| A6 | `PATCH /buses/{id}/status` | Ground / return to service | WRITE |
| A7 Drivers | `GET /drivers` | Driver table | READ |
| A7 | `GET /drivers/{id}` | Detail drawer, licence, expiry | READ |
| A7 | `GET /trips?driver_id=&date=today` | Current assignment | READ |
| A7 | `GET /incidents` (filtered client-side by reporter) | History | READ |
| A7 | `PATCH /drivers/{id}/status` | Availability | WRITE |
| A8 Incidents | `GET /incidents?status=` | The open queue | READ |
| A8 | `GET /incidents/types` | Type filter options | READ |
| A9 Incident Details | `GET /incidents/{id}` | Everything about one | READ |
| A9 | `GET /evidence/{id}` | Each cited photograph | READ |
| A9 | `GET /replacements?` | Linked replacement, if any | READ |
| A9 | `POST /incidents/{id}/acknowledge` | SUPPORT | WRITE |
| A9 | `POST /incidents/{id}/resolve` | SUPPORT | WRITE |
| A9 | `POST /incidents/{id}/close` | OPERATIONS | WRITE |
| A9 | `POST /incidents/{id}/notes` | Running commentary | WRITE |
| A9 | `POST /replacements/{id}/approve`\|`reject` | OPERATIONS | WRITE |
| A9 | `POST /replacements/{id}/dispatch`\|`arrived` | SUPPORT | WRITE |
| A10 Maintenance | `GET /maintenance-tickets` + filters | Ticket table | READ |
| A10 | `GET /maintenance-tickets/{id}` | Ticket drawer | READ |
| A10 | `GET /preventive-maintenance` | Due schedule | READ |
| A10 | `POST /maintenance-tickets` | Open — SUPPORT | WRITE |
| A10 | `POST /maintenance-tickets/{id}/assign`\|`schedule`\|`start` | SUPPORT | WRITE |
| A10 | `POST /maintenance-tickets/{id}/complete`\|`cancel` | OPERATIONS | WRITE |
| A11 Inspections | `GET /buses` | Fleet, to find the blocked ones | READ |
| A11 | `GET /buses/{id}/service-readiness` | Per not-cleared bus | READ |
| A11 | `GET /buses/{id}/inspections` | Drill-down | READ |
| A11 | `GET /inspections/{id}` | Failed items and evidence | READ |
| A12 Students | `GET /students` | Student table | READ |
| A12 | `GET /students/{id}` | Detail drawer | READ |
| A12 | `POST`\|`DELETE /students/{id}/assign-transport` | OPERATIONS | WRITE |
| A12 | `PATCH /students/{id}/status` | OPERATIONS | WRITE |
| A13 Alerts | `GET /notifications`, `/unread-count` | The office's own inbox | READ |
| A13 | `PATCH /notifications/{id}/read`, `POST /read-all` | Mark read | WRITE |
| A13 | `GET /notification-log`, `/health` | Delivery health | READ |
| A13 | `POST /notification-log/{id}/resend` | Retry a failed send | WRITE |
| A14 Announcements | `GET /announcements`, `/{id}` | List and detail | READ |
| A14 | `POST /announcements`, `PUT /announcements/{id}` | Draft — OPERATIONS | WRITE |
| A14 | `POST /announcements/{id}/publish`\|`withdraw` | OPERATIONS | WRITE |
| A15 Reports | `GET /reports/trips`, `/attendance`, `/fleet`, `/incidents`, `/maintenance`, `/occupancy` + `date`\|`from`\|`to` | Report tables | READ |
| A16 Audit | `GET /audit-logs` + filters | Who did what | READ |
| A16 | `GET /audit-logs/{id}` | One entry in full | READ |
| A16 | `GET /data-access-logs` | Who read personal data | READ |
| A16 | `GET /retention-runs` | Retention job history | READ |
| A16 | `POST /users/{id}/subject-access-export` | Subject access | WRITE |
| A17 Routes | `GET /routes`, `/routes/{id}`, `/routes/{id}/stops` | Read-only reference | READ |
| A18 Accounts | `GET /users`, `/users/{id}` | Account list | READ |
| A18 | `POST /users` | Create — SUPER_ADMIN | WRITE |
| A18 | `PATCH /users/{id}/status` | Activate/deactivate | WRITE |
| Top bar | `GET /auth/me` | Identity **and access level** | READ |
| Top bar | `GET /notifications/unread-count` | Badge | READ |
| Account menu | `POST /auth/logout`, `/logout-all`, `/auth/change-password` | Session | WRITE |

## Endpoint → screen

Reverse audit. Every endpoint in the router falls into exactly one row.

| Endpoint group | Destination | Status |
|---|---|---|
| `auth/*` | Login, top bar, account menu | Mapped |
| `trips*` reads | A1 A2 A3 A4 | Mapped |
| `trips` cancel / reassign / corrections | A3 A4 | Mapped |
| `trips` create / generate | — | Post-MVP: schedule planning |
| `trips` driver write path (`positions`, `board`, `alight`, `arrive`, `skip`, `left-behind`, `start`, `complete`) | — | **Deliberately unmapped** — the bus reports these |
| `buses*` reads | A1 A5 A6 A11 | Mapped |
| `buses` status | A6 | Mapped |
| `buses` CRUD + documents write | A6 | MVP-if-cheap |
| `fleet/documents/expiring` | A1 A5 | Mapped |
| `inspections/{id}`, `buses/{id}/inspections` | A6 A11 | Mapped |
| `inspections/checklist` | — | Reference only; the panel does not run inspections |
| `drivers*` | A7 | Mapped |
| `students*` | A12 | Mapped |
| `incidents*` | A8 A9 | Mapped |
| `evidence/{id}` | A9 A11 | Mapped |
| `evidence` POST, `evidence/categories` | — | The panel views, it does not capture |
| `maintenance-tickets*`, `preventive-maintenance` | A10 | Mapped |
| `replacements*` | A9 | Mapped |
| `notifications*` | A13, top bar | Mapped |
| `notification-log*` | A13 | Mapped |
| `notification-devices*`, `notification-preferences` | — | Per-device push; the panel registers no device |
| `announcements*` | A14 | Mapped |
| `reports/*` | A15 | Mapped |
| `audit-logs*`, `data-access-logs`, `retention-runs`, `subject-access-export` | A16 | Mapped |
| `users*` | A18 | Mapped |
| `routes*` reads | A17, A3 filters | Mapped |
| `routes*` writes, `schedules*`, `service-calendar*` | — | Network planning, post-MVP |
| `consolidations*`, `attendance-discrepancies*` | — | Post-MVP; both carry the G3-1 gap |
| `geo/*` | — | Route-building tools, `OPERATIONS`-gated, not map tools |

**Mapped to a screen: 63 of 158**, counted by parsing the table above. Every
endpoint not mapped falls into one of the groups above with a stated reason.

**No screen references an endpoint that does not exist in the router.** Verified
mechanically across all thirteen documents: 252 endpoint mentions checked, and
the only two that are not in the router are the proposed future endpoints in
`00-backend-gaps.md`, which are labelled as gaps and belong to no screen.
