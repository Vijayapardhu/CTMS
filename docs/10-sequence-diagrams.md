# CTMS — Sequence Diagrams

This document captures the runtime interaction sequences for the Campus Transport Management System (CTMS). Each diagram models a key end-to-end flow across the client apps (Flutter Student app, Flutter Driver app, Next.js/React Admin dashboard), the Laravel 12 REST API, the Laravel Reverb WebSocket server, Redis cache, PostgreSQL database, Google Maps services, and Firebase Cloud Messaging (FCM).

The diagrams are deliberately faithful to the domain model, enums, and functional requirements (FR-01..FR-15). They are intended as a build blueprint: participant names, message payloads, status transitions, and business-rule checkpoints match what the implementation must actually do.

## Participant legend

| Participant | Alias | Role in sequences |
|---|---|---|
| Student app | `SA` | Flutter app for Student role |
| Driver app | `DA` | Flutter app for Driver role |
| Admin dashboard | `AD` | Next.js/React dashboard for Admin role |
| API | `API` | Laravel 12 REST API + business logic |
| Reverb | `RV` | Laravel Reverb WebSocket broadcaster |
| Redis | `RD` | Cache + presence + live GPS buffer |
| PostgreSQL | `DB` | Primary datastore |
| Google Maps | `GM` | Routes API / Places API / Maps SDK |
| FCM | `FCM` | Firebase Cloud Messaging push |

Conventions used throughout:

- `alt` blocks model business-rule branches (approve/reject, capacity cap, auth failure).
- `loop` blocks model repeating behavior (GPS interval 5-10s).
- `par` blocks model fan-out notification delivery (Reverb + FCM concurrently).
- All API traffic is HTTPS; all WebSocket channels are authenticated with the JWT/Sanctum token; every write path implicitly appends an audit-log row (omitted from diagrams for readability).

---

## 1. Authentication / Login (FR-01)

Role-based secure login for Admin, Driver, and Student. The API validates credentials against the `User` base table, verifies the account is active, issues a JWT/Sanctum token scoped to the resolved `UserRole` (ADMIN / DRIVER / STUDENT), updates `lastLogin`, and caches the session in Redis for fast subsequent authorization checks.

```mermaid
sequenceDiagram
    autonumber
    actor U as User
    participant C as Client app
    participant API as API
    participant DB as PostgreSQL
    participant RD as Redis

    U->>C: Enter email + password
    C->>API: POST /api/auth/login
    API->>DB: SELECT user by email
    alt user not found or isActive false
        DB-->>API: no active row
        API-->>C: 401 Unauthorized
        C-->>U: Show login error
    else credentials valid
        DB-->>API: user row + passwordHash + role
        API->>API: Verify passwordHash
        alt password mismatch
            API-->>C: 401 Unauthorized
            C-->>U: Show login error
        else password ok
            API->>API: Issue JWT scoped to UserRole
            API->>DB: UPDATE lastLogin
            API->>RD: SET session token TTL
            API-->>C: 200 token + role + profile
            C->>C: Store token securely
            C-->>U: Route to role home screen
        end
    end
```

---

## 2. Start Trip, GPS Tracking Loop, and ETA (FR-06, FR-07, FR-09)

The driver starts a scheduled trip. Business rules enforce that only one active driver exists per bus and that a bus in `MAINTENANCE` or `BREAKDOWN` cannot start a trip. On success the `Trip.status` moves to `RUNNING`, the `Bus.status` moves to `RUNNING`, and the `DriverStatus` moves to `ON_TRIP`. GPS updates then stream every 5-10 seconds; each fix is buffered in Redis, persisted to `TripLocation`, and broadcast to subscribed students via Reverb. ETA is computed on demand from the Google Maps Routes API and cached.

```mermaid
sequenceDiagram
    autonumber
    participant DA as Driver app
    participant API as API
    participant DB as PostgreSQL
    participant RD as Redis
    participant RV as Reverb
    participant SA as Student app
    participant GM as Google Maps

    DA->>API: POST /api/trips/{id}/start
    API->>DB: SELECT bus + driver + schedule
    alt bus status MAINTENANCE or BREAKDOWN
        API-->>DA: 409 Bus not drivable
    else another active driver on bus
        API-->>DA: 409 Bus already in active trip
    else ok to start
        API->>DB: UPDATE Trip status RUNNING startTime
        API->>DB: UPDATE Bus status RUNNING
        API->>DB: UPDATE Driver status ON_TRIP
        API->>RV: broadcast trip.started
        RV-->>SA: trip.started event
        API-->>DA: 200 trip running

        loop every 5-10 seconds while RUNNING
            DA->>API: POST /api/trips/{id}/location
            API->>RD: buffer latest fix
            API->>DB: INSERT TripLocation
            API->>RV: broadcast bus.location
            RV-->>SA: live location update
        end

        SA->>API: GET /api/trips/{id}/eta
        API->>RD: read cached ETA
        alt ETA cache miss
            API->>GM: Routes API origin to stop
            GM-->>API: duration + distance
            API->>RD: cache ETA short TTL
        end
        API-->>SA: ETA minutes + current stop
    end
```

---

## 3. Passenger Count +1 / -1 with Capacity Cap (FR-08)

The driver adjusts the live passenger count using the +1 / -1 buttons. The core business rule is that passenger count must never exceed bus capacity. The API validates each increment against `Bus.capacity`, writes a `PassengerLog` row (`action` = Board or Exit) with `countAfterAction`, updates `Bus.currentPassengers` and `Trip.passengerCount`, and broadcasts the new occupancy.

```mermaid
sequenceDiagram
    autonumber
    participant DA as Driver app
    participant API as API
    participant DB as PostgreSQL
    participant RV as Reverb
    participant AD as Admin dashboard

    Note over DA: Driver taps +1 (Board)
    DA->>API: POST /api/trips/{id}/passenger board
    API->>DB: SELECT bus capacity + currentPassengers
    alt currentPassengers + 1 > capacity
        API-->>DA: 422 Capacity reached
        DA-->>DA: Keep count unchanged
    else within capacity
        API->>DB: INSERT PassengerLog action Board countAfterAction
        API->>DB: UPDATE Bus currentPassengers +1
        API->>DB: UPDATE Trip passengerCount
        API->>RV: broadcast passenger.count
        RV-->>AD: live occupancy update
        API-->>DA: 200 new count
    end

    Note over DA: Driver taps -1 (Exit)
    DA->>API: POST /api/trips/{id}/passenger exit
    API->>DB: SELECT currentPassengers
    alt currentPassengers = 0
        API-->>DA: 422 Count already zero
    else can decrement
        API->>DB: INSERT PassengerLog action Exit countAfterAction
        API->>DB: UPDATE Bus currentPassengers -1
        API->>DB: UPDATE Trip passengerCount
        API->>RV: broadcast passenger.count
        RV-->>AD: live occupancy update
        API-->>DA: 200 new count
    end
```

---

## 4. Incident → Maintenance Ticket → Replacement → Approval → Assignment (FR-11, FR-12, FR-14, FR-10)

The most involved flow. A driver reports a vehicle incident (breakdown, accident, tyre puncture, engine issue, battery issue). The system automatically creates a `MaintenanceTicket` from the `VehicleIncident` (every incident creates a maintenance record), flips the bus to `BREAKDOWN`, recommends available replacement buses, and waits for admin approval. On approval the `ReplacementAssignment` is created and affected students are notified across Reverb + FCM.

```mermaid
sequenceDiagram
    autonumber
    participant DA as Driver app
    participant API as API
    participant DB as PostgreSQL
    participant AD as Admin dashboard
    participant RV as Reverb
    participant FCM as FCM
    participant SA as Student app

    DA->>API: POST /api/incidents issueType severity image geo
    API->>DB: INSERT VehicleIncident status OPEN
    API->>DB: UPDATE Bus status BREAKDOWN
    Note over API,DB: Business rule - every incident creates a maintenance record
    API->>DB: INSERT MaintenanceTicket incidentId ticketNumber status OPEN
    API->>DB: SELECT buses status AVAILABLE and not MAINTENANCE
    API->>DB: INSERT ReplacementAssignment candidates status RECOMMENDED
    API->>RV: broadcast incident.reported
    RV-->>AD: incident + replacement candidates
    API-->>DA: 201 incident logged

    AD->>API: GET /api/incidents/{id}/replacements
    API->>DB: SELECT candidate buses + drivers + etaMinutes
    API-->>AD: ranked replacement options

    alt admin approves a replacement
        AD->>API: POST /api/incidents/{id}/replacement approve busId driverId
        API->>DB: UPDATE ReplacementAssignment status ASSIGNED assignedAt
        API->>DB: UPDATE replacement Bus status RUNNING
        API->>DB: UPDATE replacement Driver status ON_TRIP
        par notify affected students
            API->>RV: broadcast notification replacement bus
            RV-->>SA: in-app replacement alert
        and
            API->>FCM: push replacement bus assigned
            FCM-->>SA: push notification
        end
        API-->>AD: 200 replacement assigned
    else admin rejects
        AD->>API: POST /api/incidents/{id}/replacement reject
        API->>DB: UPDATE ReplacementAssignment status REJECTED
        API-->>AD: 200 no assignment
    end
```

---

## 5. Smart Bus Consolidation Recommendation (FR-13)

The system continuously scans running trips for low-occupancy buses on compatible routes and generates a `BusMergeRecommendation` estimating fuel saved versus the small distance increase from merging. A merge requires admin approval. On approval, passengers are consolidated onto the target trip and the source bus is freed; affected students are notified.

```mermaid
sequenceDiagram
    autonumber
    participant API as API
    participant DB as PostgreSQL
    participant AD as Admin dashboard
    participant RV as Reverb
    participant FCM as FCM
    participant SA as Student app

    Note over API: Scheduled job scans low-occupancy running trips
    API->>DB: SELECT running trips with low passengerCount
    API->>DB: INSERT BusMergeRecommendation status PENDING
    API->>RV: broadcast merge.recommended
    RV-->>AD: merge card fuel saved + distance increase

    AD->>API: GET /api/merges/{id}
    API->>DB: SELECT source + target trip metrics
    API-->>AD: sourcePassengers targetPassengers mergedPassengers fuel

    alt admin approves merge
        AD->>API: POST /api/merges/{id}/approve
        API->>DB: UPDATE BusMergeRecommendation status APPROVED approvedBy
        API->>DB: UPDATE source Trip status CANCELLED
        API->>DB: UPDATE target Trip passengerCount mergedPassengers
        API->>DB: UPDATE source Bus status AVAILABLE
        par notify reassigned students
            API->>RV: broadcast notification route change
            RV-->>SA: in-app merge alert
        and
            API->>FCM: push new bus for your trip
            FCM-->>SA: push notification
        end
        API-->>AD: 200 merge applied
    else admin rejects merge
        AD->>API: POST /api/merges/{id}/reject
        API->>DB: UPDATE BusMergeRecommendation status REJECTED
        API-->>AD: 200 recommendation dismissed
    end
```

---

## 6. SOS Alert (FR-11 emergency path)

The driver's SOS button triggers a high-priority emergency alert. Unlike a standard incident, SOS is delivered to admins immediately and in parallel across all channels with the trip's last known location, without waiting for any recommendation workflow.

```mermaid
sequenceDiagram
    autonumber
    participant DA as Driver app
    participant API as API
    participant RD as Redis
    participant DB as PostgreSQL
    participant RV as Reverb
    participant FCM as FCM
    participant AD as Admin dashboard

    Note over DA: Driver presses SOS
    DA->>API: POST /api/sos tripId geo
    API->>RD: read last known GPS fix
    API->>DB: INSERT VehicleIncident issueType SOS severity CRITICAL status OPEN
    par fan-out emergency alert
        API->>RV: broadcast sos.alert high priority
        RV-->>AD: SOS banner + live location
    and
        API->>FCM: push CRITICAL SOS to admins
        FCM-->>AD: push notification
    end
    API-->>DA: 200 SOS dispatched
    AD->>API: POST /api/sos/{id}/acknowledge
    API->>DB: UPDATE VehicleIncident status ACKNOWLEDGED
    API-->>AD: 200 acknowledged
```

---

## 7. Notification Delivery over Reverb + FCM (FR-10)

Every user-facing notification (trip started, bus nearing stop, delay, route change, replacement bus, trip completed) is persisted as a `Notification` row and delivered on two channels: Reverb for in-app real-time updates when the app is foregrounded, and FCM for push when it is backgrounded or closed. This diagram shows the generic delivery pipeline reused by all triggers.

```mermaid
sequenceDiagram
    autonumber
    participant API as API
    participant DB as PostgreSQL
    participant RD as Redis
    participant RV as Reverb
    participant FCM as FCM
    participant SA as Student app

    Note over API: A trigger fires (nearing stop / delay / etc.)
    API->>DB: INSERT Notification receiverId title message type isRead false
    API->>RD: check presence of receiver
    par dual-channel delivery
        API->>RV: broadcast to private user channel
        alt app foreground and subscribed
            RV-->>SA: live in-app notification
            SA->>API: PATCH /api/notifications/{id} read
            API->>DB: UPDATE Notification isRead true
        end
    and
        API->>FCM: send push to device tokens
        FCM-->>SA: system push notification
        SA->>SA: User taps notification
        SA->>API: GET /api/notifications/{id}
        API->>DB: UPDATE Notification isRead true sentAt
    end
    API-->>API: delivery complete
```

---

## 8. End Trip and Report Generation (FR-06, FR-15)

The driver ends the trip. The API finalizes the `Trip` (status `COMPLETED`, `endTime`, `averageSpeed`, `delayMinutes`), releases the bus and driver back to `AVAILABLE`, aggregates the trip's `TripLocation` and `PassengerLog` data into operational metrics, and makes the report available to admins. A trip-completed notification is sent to students.

```mermaid
sequenceDiagram
    autonumber
    participant DA as Driver app
    participant API as API
    participant DB as PostgreSQL
    participant RD as Redis
    participant RV as Reverb
    participant FCM as FCM
    participant SA as Student app
    participant AD as Admin dashboard

    DA->>API: POST /api/trips/{id}/end
    API->>RD: flush buffered GPS fixes
    API->>DB: INSERT remaining TripLocation rows
    API->>DB: aggregate averageSpeed + delayMinutes
    API->>DB: UPDATE Trip status COMPLETED endTime
    API->>DB: UPDATE Bus status AVAILABLE currentPassengers 0
    API->>DB: UPDATE Driver status AVAILABLE
    par notify students trip completed
        API->>RV: broadcast trip.completed
        RV-->>SA: in-app trip completed
    and
        API->>FCM: push trip completed
        FCM-->>SA: push notification
    end
    API-->>DA: 200 trip ended

    Note over AD: Admin opens reports
    AD->>API: GET /api/reports/trips filters
    API->>DB: SELECT trips + PassengerLog + TripLocation aggregates
    DB-->>API: distance, occupancy, delays, incidents
    API-->>AD: operational report + analytics
```

---

## Sequence coverage matrix

| # | Flow | Primary FRs | Key business rule enforced |
|---|---|---|---|
| 1 | Authentication / login | FR-01 | Role-scoped token; inactive users rejected |
| 2 | Start trip + GPS loop + ETA | FR-06, FR-07, FR-09 | One active driver per bus; no start on MAINTENANCE/BREAKDOWN |
| 3 | Passenger count +1 / -1 | FR-08 | Count never exceeds capacity; never below zero |
| 4 | Incident to replacement | FR-11, FR-12, FR-14, FR-10 | Every incident creates a maintenance record; replacement needs admin approval |
| 5 | Smart bus consolidation | FR-13 | Merge requires admin approval |
| 6 | SOS alert | FR-11 | Immediate parallel emergency fan-out |
| 7 | Notification delivery | FR-10 | Persist then dual-channel deliver |
| 8 | End trip + reports | FR-06, FR-15 | Finalize trip; release bus/driver to AVAILABLE |

## Cross-references

- `01-srs.md` — Software Requirements Specification (FR-01..FR-15, NFRs).
- `03-domain-model.md` — Entities, attributes, and enums used as participants and payloads.
- `06-api-specification.md` — REST endpoint contracts referenced in each sequence.
- `07-realtime-websockets.md` — Reverb channels and broadcast event schemas.
- `08-database-schema.md` — PostgreSQL tables backing the DB participant.
- `09-state-diagrams.md` — Bus, Trip, and Driver status transitions triggered by these flows.
- `11-notifications.md` — Notification and FCM delivery configuration.
