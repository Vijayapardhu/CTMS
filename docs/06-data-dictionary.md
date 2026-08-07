# Campus Transport Management System (CTMS) — Data Dictionary

**Document:** 06 — Data Dictionary
**Version:** 1.0
**Status:** Baselined against Domain Model (17 entities) and DB Design
**Audience:** Backend engineers, DBAs, QA, integrators

---

## 1. Purpose & Conventions

This document is the **exhaustive field-level reference** for every persisted attribute in the CTMS data model. It is the authoritative cross-check for the physical database schema (`07-database-design.md`) and the API contracts (`05-api-specification.md`). Every column defined in the DB layer must trace back to exactly one row here; any divergence is a defect.

### 1.1 Naming & Mapping Conventions

| Aspect | Convention |
|--------|-----------|
| Domain attribute names | `camelCase` (as shown in the SRS domain model). |
| Physical column names | `snake_case` (e.g. `firstName` → `first_name`, `busId` → `bus_id`). |
| Primary keys | `UUID` (v4), column `id`, generated server-side. |
| Foreign keys | `UUID`, suffixed `_id` (e.g. `route_id`). |
| Timestamps | `TIMESTAMPTZ` (UTC). Core entities carry `created_at` / `updated_at`. |
| Money / distances | `NUMERIC` with fixed scale (see per-column notes). |
| Enums | Native PostgreSQL `ENUM` types OR `VARCHAR` + `CHECK`; catalog in §4. |
| Booleans | `BOOLEAN`, non-null, explicit default. |
| Geo coordinates | `NUMERIC(10,7)` for latitude/longitude (≈1.1 cm precision). |

### 1.2 Column Notation Used in Tables

- **Type/Size** — logical PostgreSQL type. `VARCHAR(n)` gives max length; `TEXT` is unbounded.
- **Null** — `NO` = `NOT NULL` constraint; `YES` = nullable.
- **Default** — DB-level default; `—` means no default (value required from application).
- **Source FR** — originating functional requirement (`FR-01`..`FR-15`), or `SYS` for system/audit fields, or `PK/FK` for keys.

### 1.3 Inheritance Strategy Note

`User` is an **abstract base**. The physical model uses **class-table inheritance**: a shared `users` table holds common columns, and `students`, `drivers`, `admins` each hold a `user_id` FK (also PK) plus role-specific columns. The `users.role` discriminator (`UserRole` enum) identifies the subtype. Field tables below list base `User` columns once (§3.1) and the subtype-only columns in §3.2–§3.4.

---

## 2. Entity Catalog

| # | Entity | Table (snake_case) | Kind | Core Timestamps | Primary Source FR |
|---|--------|--------------------|------|-----------------|-------------------|
| 1 | User (abstract) | `users` | Base | Yes | FR-01 |
| 2 | Student | `students` | Subtype | via `users` | FR-04 |
| 3 | Driver | `drivers` | Subtype | via `users` | FR-03 |
| 4 | Admin | `admins` | Subtype | via `users` | FR-01 |
| 5 | Bus | `buses` | Core | Yes | FR-02 |
| 6 | Route | `routes` | Core | Yes | FR-05 |
| 7 | RouteStop | `route_stops` | Core | Yes | FR-05 |
| 8 | Schedule | `schedules` | Core | Yes | FR-05 / FR-06 |
| 9 | Trip | `trips` | Core | Yes | FR-06 |
| 10 | TripLocation | `trip_locations` | Telemetry | timestamp only | FR-07 |
| 11 | PassengerLog | `passenger_logs` | Telemetry | timestamp only | FR-08 |
| 12 | VehicleIncident | `vehicle_incidents` | Core | reportedAt | FR-11 |
| 13 | MaintenanceTicket | `maintenance_tickets` | Core | Yes | FR-14 |
| 14 | BusMergeRecommendation | `bus_merge_recommendations` | Core | Yes | FR-13 |
| 15 | ReplacementAssignment | `replacement_assignments` | Core | assignedAt | FR-12 |
| 16 | Notification | `notifications` | Core | sentAt | FR-10 |
| 17 | Announcement | `announcements` | Core | Yes | FR-10 |

---

## 3. Field Reference

### 3.1 User (abstract base) — `users`

Common identity/auth columns shared by all roles. `role` is the subtype discriminator.

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| User | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| User | role | ENUM UserRole | NO | — | Discriminator: ADMIN / DRIVER / STUDENT. | FR-01 |
| User | firstName | VARCHAR(80) | NO | — | Given name. | FR-01 |
| User | lastName | VARCHAR(80) | NO | — | Family name. | FR-01 |
| User | gender | ENUM Gender | YES | — | Male / Female / Other. | FR-01 |
| User | dateOfBirth | DATE | YES | — | Date of birth. | FR-01 |
| User | email | VARCHAR(160) | NO | — | Login identity; **UNIQUE**. | FR-01 |
| User | phone | VARCHAR(20) | YES | — | Contact number (E.164 preferred). | FR-01 |
| User | passwordHash | VARCHAR(255) | NO | — | Bcrypt/Argon2 hash; never returned by API. | FR-01 |
| User | profilePhoto | VARCHAR(500) | YES | — | URL/path to avatar asset. | FR-01 |
| User | addressLine1 | VARCHAR(160) | YES | — | Address line 1. | FR-01 |
| User | addressLine2 | VARCHAR(160) | YES | — | Address line 2. | FR-01 |
| User | city | VARCHAR(80) | YES | — | City. | FR-01 |
| User | state | VARCHAR(80) | YES | — | State/province. | FR-01 |
| User | postalCode | VARCHAR(12) | YES | — | Postal/ZIP code. | FR-01 |
| User | isActive | BOOLEAN | NO | `true` | Account enabled flag. | FR-01 |
| User | lastLogin | TIMESTAMPTZ | YES | — | Timestamp of last successful login. | FR-01 |
| User | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| User | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update (auto-touch). | SYS |

### 3.2 Student — `students`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Student | userId | UUID | NO | — | PK & FK → `users.id`. | PK/FK |
| Student | studentId | VARCHAR(40) | NO | — | Institution student identifier; **UNIQUE**. | FR-04 |
| Student | rollNumber | VARCHAR(40) | YES | — | Class roll number. | FR-04 |
| Student | admissionNumber | VARCHAR(40) | YES | — | Admission/enrollment number. | FR-04 |
| Student | department | VARCHAR(80) | YES | — | Department (e.g. CSE). | FR-04 |
| Student | course | VARCHAR(80) | YES | — | Programme/course. | FR-04 |
| Student | year | SMALLINT | YES | — | Academic year (1–5). | FR-04 |
| Student | section | VARCHAR(10) | YES | — | Class section. | FR-04 |
| Student | semester | SMALLINT | YES | — | Current semester. | FR-04 |
| Student | busId | UUID | YES | — | FK → `buses.id`; assigned bus. | FR-04 |
| Student | routeId | UUID | YES | — | FK → `routes.id`; assigned route. | FR-04 |
| Student | pickupStopId | UUID | YES | — | FK → `route_stops.id`; boarding stop. | FR-04 |
| Student | guardianName | VARCHAR(120) | YES | — | Parent/guardian name. | FR-04 |
| Student | guardianPhone | VARCHAR(20) | YES | — | Guardian contact number. | FR-04 |
| Student | transportEnabled | BOOLEAN | NO | `true` | Whether transport service is active for student. | FR-04 |

### 3.3 Driver — `drivers`

> Note: the raw source contained a duplicate "employee name" field; it is **intentionally omitted** — the driver's name is inherited from `User.firstName/lastName`.

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Driver | userId | UUID | NO | — | PK & FK → `users.id`. | PK/FK |
| Driver | employeeId | VARCHAR(40) | NO | — | HR employee identifier; **UNIQUE**. | FR-03 |
| Driver | drivingLicenseNumber | VARCHAR(40) | NO | — | Driving licence number. | FR-03 |
| Driver | licenseExpiry | DATE | YES | — | Licence expiry date. | FR-03 |
| Driver | aadhaarNumber | VARCHAR(20) | YES | — | National ID (masked at rest/log). | FR-03 |
| Driver | joiningDate | DATE | YES | — | Date of joining. | FR-03 |
| Driver | emergencyContact | VARCHAR(20) | YES | — | Emergency contact number. | FR-03 |
| Driver | assignedBusId | UUID | YES | — | FK → `buses.id`; default assigned bus. | FR-03 |
| Driver | available | BOOLEAN | NO | `true` | Availability flag for scheduling. | FR-03 |
| Driver | status | ENUM DriverStatus | NO | `AVAILABLE` | AVAILABLE / ON_TRIP / LEAVE / OFF_DUTY. | FR-03 |

### 3.4 Admin — `admins`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Admin | userId | UUID | NO | — | PK & FK → `users.id`. | PK/FK |
| Admin | employeeId | VARCHAR(40) | NO | — | HR employee identifier; **UNIQUE**. | FR-01 |
| Admin | designation | VARCHAR(80) | YES | — | Job title (e.g. Transport Officer). | FR-01 |
| Admin | officePhone | VARCHAR(20) | YES | — | Office/desk phone number. | FR-01 |

### 3.5 Bus — `buses`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Bus | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| Bus | busNumber | VARCHAR(30) | NO | — | Fleet bus number; **UNIQUE**. | FR-02 |
| Bus | registrationNumber | VARCHAR(30) | NO | — | Govt registration plate; **UNIQUE**. | FR-02 |
| Bus | chassisNumber | VARCHAR(40) | YES | — | Chassis/VIN number. | FR-02 |
| Bus | engineNumber | VARCHAR(40) | YES | — | Engine number. | FR-02 |
| Bus | manufacturer | VARCHAR(60) | YES | — | OEM (e.g. Tata, Ashok Leyland). | FR-02 |
| Bus | model | VARCHAR(60) | YES | — | Model name. | FR-02 |
| Bus | manufacturingYear | SMALLINT | YES | — | Year of manufacture. | FR-02 |
| Bus | capacity | SMALLINT | NO | — | Seating capacity; enforces passenger cap rule. | FR-02 |
| Bus | currentPassengers | SMALLINT | NO | `0` | Live passenger count; `0 ≤ x ≤ capacity`. | FR-08 |
| Bus | fuelType | VARCHAR(20) | YES | — | Diesel / Petrol / CNG / Electric. | FR-02 |
| Bus | mileage | NUMERIC(6,2) | YES | — | Fuel efficiency (km/l or km/kWh). | FR-02 |
| Bus | gpsEnabled | BOOLEAN | NO | `true` | Whether GPS tracking is available. | FR-07 |
| Bus | gpsDeviceId | VARCHAR(80) | YES | — | GPS device/hardware identifier. | FR-07 |
| Bus | status | ENUM BusStatus | NO | `AVAILABLE` | AVAILABLE / RUNNING / MAINTENANCE / BREAKDOWN / OFFLINE. | FR-02 |
| Bus | lastServiceDate | DATE | YES | — | Date of last service. | FR-14 |
| Bus | nextServiceDate | DATE | YES | — | Scheduled next service. | FR-14 |
| Bus | insuranceExpiry | DATE | YES | — | Insurance validity end date. | FR-02 |
| Bus | permitExpiry | DATE | YES | — | Transport permit expiry. | FR-02 |
| Bus | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| Bus | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.6 Route — `routes`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Route | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| Route | routeCode | VARCHAR(20) | NO | — | Short code; **UNIQUE** (e.g. R-07). | FR-05 |
| Route | routeName | VARCHAR(120) | NO | — | Human-readable route name. | FR-05 |
| Route | source | VARCHAR(120) | NO | — | Origin location label. | FR-05 |
| Route | destination | VARCHAR(120) | NO | — | Destination (typically campus). | FR-05 |
| Route | totalDistance | NUMERIC(7,2) | YES | — | Total route distance in km. | FR-05 |
| Route | estimatedDuration | INTEGER | YES | — | Estimated one-way duration in minutes. | FR-05 |
| Route | active | BOOLEAN | NO | `true` | Whether route is in service. | FR-05 |
| Route | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| Route | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.7 RouteStop — `route_stops`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| RouteStop | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| RouteStop | routeId | UUID | NO | — | FK → `routes.id`. | FK |
| RouteStop | stopName | VARCHAR(120) | NO | — | Stop name. | FR-05 |
| RouteStop | landmark | VARCHAR(160) | YES | — | Nearby landmark for rider clarity. | FR-05 |
| RouteStop | latitude | NUMERIC(10,7) | NO | — | Stop latitude. | FR-05 |
| RouteStop | longitude | NUMERIC(10,7) | NO | — | Stop longitude. | FR-05 |
| RouteStop | sequence | INTEGER | NO | — | Order of stop along route (1..n); unique per route. | FR-05 |
| RouteStop | geofenceRadius | DOUBLE PRECISION | YES | `100` | Geofence radius in meters for arrival detection. | FR-10 |
| RouteStop | expectedArrival | TIME | YES | — | Planned arrival time at stop. | FR-05 |
| RouteStop | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| RouteStop | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.8 Schedule — `schedules`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Schedule | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| Schedule | routeId | UUID | NO | — | FK → `routes.id`. | FK |
| Schedule | busId | UUID | YES | — | FK → `buses.id`; planned bus. | FK |
| Schedule | dayOfWeek | ENUM DayOfWeek | NO | — | MONDAY..SUNDAY. | FR-05 |
| Schedule | departureTime | TIME | NO | — | Scheduled departure. | FR-05 |
| Schedule | arrivalTime | TIME | YES | — | Scheduled arrival. | FR-05 |
| Schedule | active | BOOLEAN | NO | `true` | Whether schedule entry is active. | FR-05 |
| Schedule | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| Schedule | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.9 Trip — `trips`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Trip | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| Trip | scheduleId | UUID | YES | — | FK → `schedules.id`; originating schedule. | FK |
| Trip | busId | UUID | NO | — | FK → `buses.id`; operating bus. | FR-06 |
| Trip | driverId | UUID | NO | — | FK → `drivers.user_id`; operating driver. | FR-06 |
| Trip | routeId | UUID | NO | — | FK → `routes.id`. | FR-06 |
| Trip | tripDate | DATE | NO | — | Calendar date of trip. | FR-06 |
| Trip | startTime | TIMESTAMPTZ | YES | — | Actual trip start (driver starts trip). | FR-06 |
| Trip | endTime | TIMESTAMPTZ | YES | — | Actual trip end. | FR-06 |
| Trip | status | ENUM TripStatus | NO | `SCHEDULED` | SCHEDULED / RUNNING / COMPLETED / CANCELLED. | FR-06 |
| Trip | passengerCount | SMALLINT | NO | `0` | Current/final passenger count for trip. | FR-08 |
| Trip | averageSpeed | DOUBLE PRECISION | YES | — | Average speed (km/h) over trip. | FR-07 |
| Trip | delayMinutes | INTEGER | NO | `0` | Delay vs schedule in minutes. | FR-09 |
| Trip | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| Trip | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.10 TripLocation — `trip_locations`

High-volume GPS telemetry (5–10 s cadence). Append-only; partitioned by day/trip in the DB layer.

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| TripLocation | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| TripLocation | tripId | UUID | NO | — | FK → `trips.id`. | FK |
| TripLocation | latitude | NUMERIC(10,7) | NO | — | GPS latitude. | FR-07 |
| TripLocation | longitude | NUMERIC(10,7) | NO | — | GPS longitude. | FR-07 |
| TripLocation | speed | DOUBLE PRECISION | YES | — | Instantaneous speed (km/h). | FR-07 |
| TripLocation | heading | DOUBLE PRECISION | YES | — | Bearing in degrees (0–360). | FR-07 |
| TripLocation | accuracy | DOUBLE PRECISION | YES | — | Horizontal accuracy in meters. | FR-07 |
| TripLocation | timestamp | TIMESTAMPTZ | NO | `now()` | Device fix time (UTC). | FR-07 |

### 3.11 PassengerLog — `passenger_logs`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| PassengerLog | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| PassengerLog | tripId | UUID | NO | — | FK → `trips.id`. | FK |
| PassengerLog | action | ENUM PassengerAction | NO | — | Board / Exit. | FR-08 |
| PassengerLog | countAfterAction | SMALLINT | NO | — | Passenger count after this event. | FR-08 |
| PassengerLog | timestamp | TIMESTAMPTZ | NO | `now()` | Event time. | FR-08 |

### 3.12 VehicleIncident — `vehicle_incidents`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| VehicleIncident | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| VehicleIncident | tripId | UUID | YES | — | FK → `trips.id`; trip during which reported. | FK |
| VehicleIncident | busId | UUID | NO | — | FK → `buses.id`; affected bus. | FR-11 |
| VehicleIncident | driverId | UUID | NO | — | FK → `drivers.user_id`; reporting driver. | FR-11 |
| VehicleIncident | issueType | VARCHAR(40) | NO | — | breakdown / accident / tyre puncture / engine issue / battery issue. | FR-11 |
| VehicleIncident | severity | ENUM Severity | NO | — | LOW / MEDIUM / HIGH / CRITICAL. | FR-11 |
| VehicleIncident | description | TEXT | YES | — | Free-text detail. | FR-11 |
| VehicleIncident | imageUrl | VARCHAR(500) | YES | — | Attached photo evidence URL. | FR-11 |
| VehicleIncident | latitude | NUMERIC(10,7) | YES | — | Incident latitude. | FR-11 |
| VehicleIncident | longitude | NUMERIC(10,7) | YES | — | Incident longitude. | FR-11 |
| VehicleIncident | status | ENUM IncidentStatus | NO | `REPORTED` | REPORTED / ACKNOWLEDGED / IN_PROGRESS / RESOLVED / CLOSED. | FR-11 |
| VehicleIncident | reportedAt | TIMESTAMPTZ | NO | `now()` | Report timestamp. | FR-11 |

### 3.13 MaintenanceTicket — `maintenance_tickets`

Auto-created from each incident (business rule: every incident creates a maintenance record).

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| MaintenanceTicket | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| MaintenanceTicket | incidentId | UUID | NO | — | FK → `vehicle_incidents.id`; **UNIQUE** (1:1). | FK |
| MaintenanceTicket | busId | UUID | NO | — | FK → `buses.id`. | FR-14 |
| MaintenanceTicket | ticketNumber | VARCHAR(30) | NO | — | Human ticket ref; **UNIQUE**. | FR-14 |
| MaintenanceTicket | assignedTechnician | VARCHAR(120) | YES | — | Technician name/handle. | FR-14 |
| MaintenanceTicket | status | ENUM MaintenanceStatus | NO | `OPEN` | OPEN / ASSIGNED / IN_PROGRESS / COMPLETED / CLOSED. | FR-14 |
| MaintenanceTicket | repairStart | TIMESTAMPTZ | YES | — | Repair start time. | FR-14 |
| MaintenanceTicket | repairEnd | TIMESTAMPTZ | YES | — | Repair completion time. | FR-14 |
| MaintenanceTicket | estimatedCost | NUMERIC(10,2) | YES | — | Estimated repair cost. | FR-14 |
| MaintenanceTicket | remarks | TEXT | YES | — | Technician remarks. | FR-14 |
| MaintenanceTicket | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| MaintenanceTicket | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.14 BusMergeRecommendation — `bus_merge_recommendations`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| BusMergeRecommendation | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| BusMergeRecommendation | sourceTripId | UUID | NO | — | FK → `trips.id`; low-occupancy trip to fold in. | FK |
| BusMergeRecommendation | targetTripId | UUID | NO | — | FK → `trips.id`; trip absorbing passengers. | FK |
| BusMergeRecommendation | sourcePassengers | SMALLINT | NO | — | Passengers on source trip. | FR-13 |
| BusMergeRecommendation | targetPassengers | SMALLINT | NO | — | Passengers on target trip. | FR-13 |
| BusMergeRecommendation | mergedPassengers | SMALLINT | NO | — | Combined passenger count after merge. | FR-13 |
| BusMergeRecommendation | estimatedFuelSaved | NUMERIC(8,2) | YES | — | Estimated fuel/litres saved. | FR-13 |
| BusMergeRecommendation | distanceIncrease | NUMERIC(7,2) | YES | — | Added distance (km) from re-routing. | FR-13 |
| BusMergeRecommendation | status | ENUM MergeStatus | NO | `PENDING` | PENDING / APPROVED / REJECTED / EXECUTED. | FR-13 |
| BusMergeRecommendation | approvedBy | UUID | YES | — | FK → `admins.user_id`; approving admin. | FR-13 |
| BusMergeRecommendation | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| BusMergeRecommendation | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

### 3.15 ReplacementAssignment — `replacement_assignments`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| ReplacementAssignment | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| ReplacementAssignment | incidentId | UUID | NO | — | FK → `vehicle_incidents.id`; triggering incident. | FK |
| ReplacementAssignment | replacementBusId | UUID | NO | — | FK → `buses.id`; replacement vehicle. | FR-12 |
| ReplacementAssignment | replacementDriverId | UUID | YES | — | FK → `drivers.user_id`; replacement driver. | FR-12 |
| ReplacementAssignment | etaMinutes | INTEGER | YES | — | ETA for replacement to reach scene (min). | FR-12 |
| ReplacementAssignment | assignedAt | TIMESTAMPTZ | NO | `now()` | Assignment timestamp. | FR-12 |
| ReplacementAssignment | status | ENUM ReplacementStatus | NO | `RECOMMENDED` | RECOMMENDED / APPROVED / DISPATCHED / ARRIVED / COMPLETED / CANCELLED. | FR-12 |

### 3.16 Notification — `notifications`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Notification | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| Notification | receiverId | UUID | NO | — | FK → `users.id`; recipient. | FK |
| Notification | title | VARCHAR(160) | NO | — | Short heading. | FR-10 |
| Notification | message | TEXT | NO | — | Body text. | FR-10 |
| Notification | type | ENUM NotificationType | NO | — | See §4 catalog. | FR-10 |
| Notification | isRead | BOOLEAN | NO | `false` | Read/unread flag. | FR-10 |
| Notification | sentAt | TIMESTAMPTZ | NO | `now()` | Dispatch timestamp. | FR-10 |

### 3.17 Announcement — `announcements`

| Entity | Field | Type/Size | Null | Default | Description | Source FR |
|--------|-------|-----------|------|---------|-------------|-----------|
| Announcement | id | UUID | NO | `gen_random_uuid()` | Primary key. | PK |
| Announcement | title | VARCHAR(160) | NO | — | Announcement heading. | FR-10 |
| Announcement | description | TEXT | NO | — | Announcement body. | FR-10 |
| Announcement | audience | ENUM Audience | NO | `ALL` | ALL / STUDENTS / DRIVERS / ADMINS. | FR-10 |
| Announcement | publishAt | TIMESTAMPTZ | YES | `now()` | When it becomes visible. | FR-10 |
| Announcement | expireAt | TIMESTAMPTZ | YES | — | When it stops showing. | FR-10 |
| Announcement | createdAt | TIMESTAMPTZ | NO | `now()` | Row creation. | SYS |
| Announcement | updatedAt | TIMESTAMPTZ | NO | `now()` | Last update. | SYS |

---

## 4. Enum Value Catalog

Canonical enum types. SRS-defined enums are marked **[SRS]**; enums implied by attributes and fixed here for the build are marked **[DERIVED]** and must be mirrored exactly in the DB and API layers.

### 4.1 UserRole **[SRS]**
| Value | Meaning |
|-------|---------|
| ADMIN | Transport administrator. |
| DRIVER | Bus driver. |
| STUDENT | Student passenger. |

### 4.2 BusStatus **[SRS]**
| Value | Meaning |
|-------|---------|
| AVAILABLE | Idle, assignable. |
| RUNNING | Currently on an active trip. |
| MAINTENANCE | In service/repair; cannot be assigned. |
| BREAKDOWN | Broke down; incident open. |
| OFFLINE | Powered off / no GPS. |

### 4.3 DriverStatus **[SRS]**
| Value | Meaning |
|-------|---------|
| AVAILABLE | Free to be assigned. |
| ON_TRIP | Currently driving a trip. |
| LEAVE | On leave. |
| OFF_DUTY | Not on shift. |

### 4.4 TripStatus **[SRS]**
| Value | Meaning |
|-------|---------|
| SCHEDULED | Created, not yet started. |
| RUNNING | Trip in progress. |
| COMPLETED | Trip finished normally. |
| CANCELLED | Trip cancelled. |

### 4.5 Gender **[DERIVED]**
`Male`, `Female`, `Other`.

### 4.6 DayOfWeek **[DERIVED]**
`MONDAY`, `TUESDAY`, `WEDNESDAY`, `THURSDAY`, `FRIDAY`, `SATURDAY`, `SUNDAY`.

### 4.7 PassengerAction **[DERIVED]**
`Board`, `Exit`.

### 4.8 Severity (incident) **[DERIVED]**
`LOW`, `MEDIUM`, `HIGH`, `CRITICAL`.

### 4.9 IncidentStatus **[DERIVED]**
`REPORTED`, `ACKNOWLEDGED`, `IN_PROGRESS`, `RESOLVED`, `CLOSED`.

### 4.10 MaintenanceStatus **[DERIVED]**
`OPEN`, `ASSIGNED`, `IN_PROGRESS`, `COMPLETED`, `CLOSED`.

### 4.11 MergeStatus **[DERIVED]**
`PENDING`, `APPROVED`, `REJECTED`, `EXECUTED`.

### 4.12 ReplacementStatus **[DERIVED]**
`RECOMMENDED`, `APPROVED`, `DISPATCHED`, `ARRIVED`, `COMPLETED`, `CANCELLED`.

### 4.13 NotificationType **[DERIVED]** (aligned to FR-10 event set)
| Value | Trigger |
|-------|---------|
| TRIP_STARTED | Driver starts trip. |
| BUS_NEARING_STOP | Bus enters stop geofence. |
| DELAY | Trip delay threshold exceeded. |
| ROUTE_CHANGE | Route/stop altered. |
| REPLACEMENT_BUS | Replacement bus assigned. |
| TRIP_COMPLETED | Trip ended. |
| SOS | Driver SOS raised. |
| ANNOUNCEMENT | General broadcast. |

### 4.14 Audience (announcement) **[DERIVED]**
`ALL`, `STUDENTS`, `DRIVERS`, `ADMINS`.

---

## 5. Cross-Entity Constraints & Data-Integrity Rules

These rules govern column values beyond simple typing and MUST be enforced at DB (`CHECK`/unique/FK) and/or application layer.

| ID | Rule | Enforcing columns |
|----|------|-------------------|
| DR-01 | Passenger count never exceeds capacity. | `buses.current_passengers ≤ buses.capacity`; `trips.passenger_count ≤ buses.capacity`. |
| DR-02 | Only one active driver per bus during a trip. | Partial unique index on `trips(bus_id)` where `status = 'RUNNING'`. |
| DR-03 | A bus in maintenance cannot be assigned. | App guard: reject Trip/Schedule when `buses.status IN ('MAINTENANCE','BREAKDOWN')`. |
| DR-04 | Bus merge requires admin approval. | `bus_merge_recommendations.status = 'APPROVED'` requires non-null `approved_by`. |
| DR-05 | Replacement bus requires admin approval. | `replacement_assignments.status` advances past `RECOMMENDED` only via admin action. |
| DR-06 | Students view only their assigned bus. | Row-scope on `students.bus_id`; enforced at API/authorization. |
| DR-07 | Every incident creates a maintenance record. | 1:1 `maintenance_tickets.incident_id` UNIQUE; created transactionally with incident. |
| DR-08 | Email uniqueness across all users. | UNIQUE(`users.email`). |
| DR-09 | RouteStop sequence unique within a route. | UNIQUE(`route_stops.route_id, route_stops.sequence`). |
| DR-10 | Non-negative counters. | `CHECK (current_passengers ≥ 0)`, `CHECK (passenger_count ≥ 0)`, `CHECK (delay_minutes ≥ 0)`. |

---

## 6. Type Sizing Rationale (quick reference)

| Logical type | Physical choice | Notes |
|--------------|-----------------|-------|
| Identifier / free short text | `VARCHAR(20–40)` | Codes, numbers, IDs. |
| Name / label | `VARCHAR(60–160)` | Person, place, title. |
| Long text | `TEXT` | Descriptions, remarks, messages. |
| Geo coordinate | `NUMERIC(10,7)` | ~11 mm precision. |
| Money | `NUMERIC(10,2)` | Costs, fuel value. |
| Distance (km) | `NUMERIC(7,2)` | Up to 99,999.99 km. |
| Mileage | `NUMERIC(6,2)` | km/l or km/kWh. |
| Speed / heading / accuracy | `DOUBLE PRECISION` | Telemetry precision. |
| Small counts (year, capacity, passengers, semester) | `SMALLINT` | ≤ 32,767. |
| Durations / delays / ETA (minutes) | `INTEGER` | Minutes. |
| Timestamps | `TIMESTAMPTZ` | Always UTC. |

---

## 7. Cross-references

- `03-domain-model.md` — entity/relationship definitions this dictionary details.
- `05-api-specification.md` — request/response DTOs that serialize these fields (camelCase).
- `07-database-design.md` — physical schema, indexes, partitioning, and DDL mapping (snake_case).
- `08-erd.md` — entity-relationship diagram of the 17 entities.
- `04-functional-requirements.md` — FR-01..FR-15 traceability source.
