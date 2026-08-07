# Backend System Architecture Plan

## Campus Transport Management System (CTMS) - Laravel 12 Backend

---

## 1. Architecture Overview

### Layered Architecture
```
┌─────────────────────────────────────────────────────────────┐
│                    API Layer (REST)                         │
│  Controllers, Route Handlers, Request/Response Formatting   │
├─────────────────────────────────────────────────────────────┤
│                   Business Logic Layer                      │
│  Services, Domain Logic, Workflows, State Machines          │
├─────────────────────────────────────────────────────────────┤
│                   Data Access Layer                         │
│  Eloquent Models, Repositories, Database Queries            │
├─────────────────────────────────────────────────────────────┤
│                   Database Layer                            │
│  PostgreSQL Tables, Migrations, Indexes                     │
├─────────────────────────────────────────────────────────────┤
│              Realtime Layer (WebSockets)                    │
│  Reverb Channels, Broadcasting, Event Streaming             │
├─────────────────────────────────────────────────────────────┤
│         Infrastructure & Integration Services               │
│  Google Maps, Firebase FCM, Redis, File Storage             │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Directory Structure

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── AuthController.php (login, logout, refresh)
│   │   │   │   └── PasswordController.php (change password)
│   │   │   ├── Users/
│   │   │   │   ├── UserController.php (CRUD, profile)
│   │   │   │   ├── StudentController.php
│   │   │   │   ├── DriverController.php
│   │   │   │   └── AdminController.php
│   │   │   ├── Buses/
│   │   │   │   ├── BusController.php (CRUD, status)
│   │   │   │   └── BusAssignmentController.php
│   │   │   ├── Routes/
│   │   │   │   ├── RouteController.php (CRUD)
│   │   │   │   ├── RouteStopController.php
│   │   │   │   └── ScheduleController.php
│   │   │   ├── Trips/
│   │   │   │   ├── TripController.php (CRUD, generation)
│   │   │   │   ├── PassengerController.php (+1/-1 counting)
│   │   │   │   └── TripStatusController.php
│   │   │   ├── GPS/
│   │   │   │   └── GPSController.php (receive, stream)
│   │   │   ├── ETA/
│   │   │   │   └── ETAController.php (fetch ETA)
│   │   │   ├── Incidents/
│   │   │   │   ├── IncidentController.php (report)
│   │   │   │   ├── ReplacementController.php (approve)
│   │   │   │   └── MergeController.php (approve)
│   │   │   ├── Notifications/
│   │   │   │   ├── NotificationController.php (CRUD)
│   │   │   │   └── AnnouncementController.php
│   │   │   └── Reports/
│   │   │       └── ReportController.php (fetch/export)
│   │   ├── Middleware/
│   │   │   ├── AuthenticateRequest.php
│   │   │   ├── RoleAuthorize.php
│   │   │   ├── RateLimiter.php
│   │   │   ├── CorrelationId.php (audit logging)
│   │   │   └── LogRequestResponse.php
│   │   └── Requests/
│   │       ├── Auth/
│   │       ├── Users/
│   │       ├── Buses/
│   │       ├── Trips/
│   │       └── ...
│   ├── Models/
│   │   ├── User.php (abstract base)
│   │   ├── Student.php
│   │   ├── Driver.php
│   │   ├── Admin.php
│   │   ├── Bus.php
│   │   ├── Route.php
│   │   ├── RouteStop.php
│   │   ├── Schedule.php
│   │   ├── Trip.php
│   │   ├── TripLocation.php
│   │   ├── PassengerLog.php
│   │   ├── VehicleIncident.php
│   │   ├── MaintenanceTicket.php
│   │   ├── BusMergeRecommendation.php
│   │   ├── ReplacementAssignment.php
│   │   ├── Notification.php
│   │   └── Announcement.php
│   ├── Services/
│   │   ├── Auth/
│   │   │   └── AuthService.php (JWT, audit)
│   │   ├── GPS/
│   │   │   └── GPSService.php (validation, storage)
│   │   ├── ETA/
│   │   │   ├── ETAService.php (Maps API)
│   │   │   └── ETACache.php (caching)
│   │   ├── Incident/
│   │   │   ├── IncidentService.php (creation, workflow)
│   │   │   ├── ReplacementService.php (recommend, assign)
│   │   │   └── MergeService.php (recommend, approve)
│   │   ├── Notification/
│   │   │   ├── NotificationService.php (multi-channel)
│   │   │   ├── FCMClient.php (Firebase integration)
│   │   │   └── NotificationTemplate.php (templating)
│   │   ├── Trip/
│   │   │   ├── TripService.php (CRUD, generation)
│   │   │   ├── TripGenerator.php (batch creation)
│   │   │   └── PassengerService.php (capacity validation)
│   │   ├── Route/
│   │   │   └── RouteService.php (geofence, validation)
│   │   ├── Report/
│   │   │   ├── ReportService.php (generation)
│   │   │   ├── TripReportGenerator.php
│   │   │   ├── OccupancyReportGenerator.php
│   │   │   └── IncidentReportGenerator.php
│   │   ├── File/
│   │   │   └── FileUploadService.php (S3/local storage)
│   │   └── Queue/
│   │       └── QueueService.php (Redis jobs)
│   ├── Repositories/
│   │   ├── TripRepository.php
│   │   ├── BusRepository.php
│   │   ├── IncidentRepository.php
│   │   └── ...
│   ├── Events/
│   │   ├── GPSLocationReceived.php
│   │   ├── TripStarted.php
│   │   ├── BusNearingStop.php
│   │   ├── IncidentReported.php
│   │   ├── BusDelayed.php
│   │   ├── PassengerCountChanged.php
│   │   └── TripCompleted.php
│   ├── Listeners/
│   │   ├── SendGPSBroadcast.php (WebSocket)
│   │   ├── SendETAUpdate.php (WebSocket)
│   │   ├── SendNotification.php (FCM)
│   │   ├── CreateMaintenanceTicket.php
│   │   └── LogAuditTrail.php
│   ├── Jobs/
│   │   ├── GenerateDailyTrips.php
│   │   ├── CalculateETA.php
│   │   ├── SendBulkNotifications.php
│   │   ├── ProcessIncidentWorkflow.php
│   │   └── GenerateReports.php
│   ├── Broadcasting/
│   │   ├── Channels/
│   │   │   ├── GPSLocationChannel.php (private)
│   │   │   ├── TripUpdatesChannel.php (private)
│   │   │   ├── NotificationChannel.php (private)
│   │   │   └── AdminPresenceChannel.php (presence)
│   │   └── Events/ (Broadcastable events)
│   ├── Rules/
│   │   ├── ValidGeofence.php
│   │   ├── ValidCapacity.php
│   │   ├── ValidLicenseExpiry.php
│   │   └── ValidPhoneNumber.php
│   ├── Traits/
│   │   ├── HasAuditTrail.php
│   │   ├── HasTimestamps.php
│   │   └── HasSoftDeletes.php
│   ├── Enums/
│   │   ├── UserRole.php (ADMIN, DRIVER, STUDENT)
│   │   ├── BusStatus.php (AVAILABLE, RUNNING, MAINTENANCE, BREAKDOWN, OFFLINE)
│   │   ├── DriverStatus.php (AVAILABLE, ON_TRIP, LEAVE, OFF_DUTY)
│   │   ├── TripStatus.php (SCHEDULED, RUNNING, COMPLETED, CANCELLED)
│   │   ├── IncidentSeverity.php (LOW, MEDIUM, HIGH, CRITICAL)
│   │   └── NotificationType.php (TRIP_START, BUS_NEARING, DELAY, etc)
│   └── Exceptions/
│       ├── BusFullException.php
│       ├── InvalidTripStateException.php
│       ├── UnauthorizedActionException.php
│       └── ExternalServiceException.php
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000000_create_users_table.php
│   │   ├── 2024_01_01_000100_create_students_table.php
│   │   ├── 2024_01_01_000200_create_drivers_table.php
│   │   ├── 2024_01_01_000300_create_admins_table.php
│   │   ├── 2024_01_01_001000_create_buses_table.php
│   │   ├── 2024_01_01_001100_create_routes_table.php
│   │   ├── 2024_01_01_001200_create_route_stops_table.php
│   │   ├── 2024_01_01_001300_create_schedules_table.php
│   │   ├── 2024_01_01_002000_create_trips_table.php
│   │   ├── 2024_01_01_002100_create_trip_locations_table.php
│   │   ├── 2024_01_01_002200_create_passenger_logs_table.php
│   │   ├── 2024_01_01_003000_create_incidents_table.php
│   │   ├── 2024_01_01_003100_create_maintenance_tickets_table.php
│   │   ├── 2024_01_01_003200_create_replacement_assignments_table.php
│   │   ├── 2024_01_01_003300_create_bus_merge_recommendations_table.php
│   │   ├── 2024_01_01_004000_create_notifications_table.php
│   │   ├── 2024_01_01_004100_create_announcements_table.php
│   │   ├── 2024_01_01_004200_create_audit_logs_table.php
│   │   └── 2024_01_01_999999_create_indexes.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       ├── AdminSeeder.php
│       ├── BusSeeder.php
│       ├── RouteSeeder.php
│       ├── DriverSeeder.php
│       └── StudentSeeder.php
├── routes/
│   ├── api.php (REST API routes)
│   ├── channels.php (WebSocket channels)
│   ├── web.php (if needed)
│   └── console.php (scheduled tasks)
├── config/
│   ├── app.php
│   ├── database.php (PostgreSQL)
│   ├── cache.php (Redis)
│   ├── queue.php (Redis queues)
│   ├── broadcast.php (Reverb)
│   ├── services.php (Google Maps, FCM keys)
│   └── ctms.php (app-specific config)
├── tests/
│   ├── Feature/
│   │   ├── Auth/
│   │   ├── Users/
│   │   ├── Buses/
│   │   ├── Trips/
│   │   ├── GPS/
│   │   └── Incidents/
│   └── Unit/
│       ├── Services/
│       └── Models/
├── .env.example
├── docker-compose.yml (PostgreSQL, Redis, Reverb)
├── Dockerfile
├── docker-entrypoint.sh
└── composer.json
```

---

## 3. Core Components & Implementation Phases

### Phase 1: Foundation (Week 1-2)
**Dependencies: None**

1. **Laravel 12 Setup** → Configure PostgreSQL, Redis, environment
2. **Database Schema** → Run all migrations
3. **Authentication & Authorization** → JWT/Sanctum, role-based access
4. **User Models & Base API** → User, Student, Driver, Admin with CRUD

### Phase 2: Core Domain (Week 3-4)
**Depends on: Phase 1**

5. **Bus & Route Management** → Bus, Route, RouteStop, Schedule models & API
6. **Trip Management** → Trip generation, state machine, CRUD API
7. **Passenger Counting** → PassengerLog, +1/-1 API with capacity validation

### Phase 3: Real-time & Integration (Week 5-6)
**Depends on: Phases 1-2**

8. **GPS Tracking** → Ingest, validate, store GPS data
9. **GPS WebSocket Broadcasting** → Stream positions to students live
10. **ETA Service** → Google Maps integration, caching, ETA API + WebSocket

### Phase 4: Incident Management (Week 7)
**Depends on: Phases 1-3**

11. **Incident Reporting** → Report, photograph, location capture
12. **Replacement Bus Workflow** → Recommendations, approvals, assignments
13. **Bus Merge Workflow** → Low-occupancy detection, recommendations, approvals
14. **Maintenance Tickets** → Auto-creation from incidents, workflow

### Phase 5: Notifications & Analytics (Week 8)
**Depends on: All prior phases**

15. **FCM Integration** → Firebase push notifications, multi-channel
16. **Notification Service** → In-app, push, SMS templates
17. **Announcements** → Create, publish, expire
18. **Reports & Analytics** → Trip, occupancy, fleet, incident reports

### Phase 6: Polish & Deployment (Week 9)
**Depends on: All prior phases**

19. **Testing & QA** → Unit, feature, integration tests
20. **API Documentation** → OpenAPI/Swagger docs
21. **Docker & DevOps** → Dockerfile, docker-compose, CI/CD
22. **Performance Tuning** → Caching, indexing, query optimization

---

## 4. API Layer Design

### REST Endpoints Structure
```
/api/v1/
├── auth/
│   ├── POST /login
│   ├── POST /logout
│   ├── POST /refresh
│   └── POST /password/change
├── users/
│   ├── GET /users
│   ├── POST /users
│   ├── GET /users/{id}
│   ├── PUT /users/{id}
│   ├── DELETE /users/{id}
│   └── GET /users/{id}/profile
├── students/
│   ├── GET /students
│   ├── POST /students
│   ├── GET /students/{id}
│   ├── PUT /students/{id}
│   ├── GET /students/{id}/bus
│   └── GET /students/{id}/eta
├── drivers/
│   ├── GET /drivers
│   ├── POST /drivers
│   ├── GET /drivers/{id}
│   ├── PUT /drivers/{id}
│   └── PATCH /drivers/{id}/status
├── buses/
│   ├── GET /buses
│   ├── POST /buses
│   ├── GET /buses/{id}
│   ├── PUT /buses/{id}
│   └── PATCH /buses/{id}/status
├── routes/
│   ├── GET /routes
│   ├── POST /routes
│   ├── GET /routes/{id}
│   ├── PUT /routes/{id}
│   ├── GET /routes/{id}/stops
│   └── POST /routes/{id}/stops
├── schedules/
│   ├── GET /schedules
│   ├── POST /schedules
│   └── GET /schedules/{id}
├── trips/
│   ├── GET /trips
│   ├── POST /trips (generate)
│   ├── GET /trips/{id}
│   ├── PATCH /trips/{id}/status
│   ├── POST /trips/{id}/start
│   └── POST /trips/{id}/complete
├── passengers/
│   ├── POST /trips/{id}/passengers/board
│   ├── POST /trips/{id}/passengers/alight
│   └── GET /trips/{id}/passengers
├── gps/
│   ├── POST /gps/location (receive)
│   └── GET /trips/{id}/locations (fetch)
├── eta/
│   ├── GET /trips/{id}/eta
│   └── GET /trips/{id}/stops/{stopId}/eta
├── incidents/
│   ├── POST /incidents (report)
│   ├── GET /incidents
│   ├── GET /incidents/{id}
│   ├── GET /incidents/{id}/replacements
│   └── POST /incidents/{id}/replacements/{id}/approve
├── merges/
│   ├── GET /trips/merges (recommendations)
│   ├── POST /trips/merges/{id}/approve
│   └── POST /trips/merges/{id}/reject
├── notifications/
│   ├── GET /notifications
│   ├── POST /notifications/{id}/read
│   └── DELETE /notifications/{id}
├── announcements/
│   ├── GET /announcements
│   ├── POST /announcements
│   └── GET /announcements/{id}
└── reports/
    ├── GET /reports/trips
    ├── GET /reports/occupancy
    ├── GET /reports/incidents
    └── GET /reports/export
```

---

## 5. Database Layer Design

### Key Tables (from `/docs/05-database-design.md`)
- **users** — Base user table with polymorphic relationship to Student/Driver/Admin
- **buses** — Fleet inventory with status and service tracking
- **routes** — Transport routes with source/destination
- **route_stops** — Ordered stops per route with geofencing
- **schedules** — Weekly schedules linking routes to buses
- **trips** — Daily trip instances with state tracking
- **trip_locations** — GPS coordinates logged every 5-10s
- **passenger_logs** — +1/-1 counts per stop per trip
- **vehicle_incidents** — Breakdowns, accidents, tyre, engine, battery issues
- **maintenance_tickets** — Auto-created from incidents, repair workflow
- **bus_merge_recommendations** — Low-occupancy merge suggestions
- **replacement_assignments** — Replacement bus allocations
- **notifications** — In-app notification records
- **announcements** — System-wide messages
- **audit_logs** — All state-changing actions (admin approval workflows)

### Key Indexes
- `trips(route_id, trip_date, status)` — efficient trip queries
- `trip_locations(trip_id, timestamp)` — GPS time-series queries
- `buses(status, assigned_driver_id)` — bus availability queries
- `users(email)` — auth queries
- `passenger_logs(trip_id, stop_sequence)` — occupancy queries

---

## 6. Service Layer Design

### Key Services

**AuthService**
- JWT token generation & validation
- Role-based access control (RBAC)
- Audit trail logging

**GPSService**
- Validate GPS coordinate accuracy & rate-limiting
- Store locations in PostgreSQL
- Broadcast to WebSocket subscribers

**ETAService**
- Call Google Maps Routes API
- Cache results in Redis (5-min TTL)
- Fallback to static estimates

**NotificationService**
- Build FCM payload
- Store in DB for audit
- Send via Firebase Cloud Messaging
- In-app notifications via WebSocket

**IncidentService**
- Create incident with attachment
- Auto-generate maintenance ticket
- Recommend replacement buses

**ReplacementService**
- Query available buses near incident location
- Calculate ETA to incident location
- Create recommendation record
- Admin approval workflow

**MergeService**
- Identify low-occupancy trips (e.g., <30% capacity)
- Calculate fuel savings
- Create recommendation
- Admin approval workflow

**TripService**
- Generate daily trips from schedules
- Assign buses & drivers
- Manage trip lifecycle (scheduled → running → completed)

**ReportService**
- Generate trip occupancy reports
- Fleet utilization analytics
- Incident trends & severity
- Export to CSV/PDF

---

## 7. Real-time Layer (WebSocket / Reverb)

### Channels

**GPS Location Channel** (`private-trips.{trip_id}.gps`)
- Publisher: Driver app (GPS update event)
- Subscribers: Connected students for that trip
- Message: `{ latitude, longitude, speed, heading, timestamp }`
- Frequency: Every 5-10 seconds

**Trip Updates Channel** (`private-trips.{trip_id}.updates`)
- Publisher: Backend (trip state changes)
- Subscribers: All connected passengers + admin
- Message: `{ trip_id, status, delay_minutes, cancelled }`

**Notification Channel** (`private-user.{user_id}.notifications`)
- Publisher: Backend notification service
- Subscribers: User's connected devices
- Message: `{ title, message, type, action_url }`

**Admin Presence Channel** (`presence-admin`)
- Publisher: Admin dashboard
- Subscribers: Other admins
- Message: Who is currently online, viewing which trips

---

## 8. Error Handling & Validation

### Custom Exceptions
- `BusFullException` — Capacity exceeded
- `InvalidTripStateException` — Invalid state transition
- `UnauthorizedActionException` — RBAC violation
- `ExternalServiceException` — Google Maps, FCM down
- `GeofenceViolationException` — Bus left geofence unexpectedly

### Validation Rules
- Custom geofence validation (point-in-polygon)
- Bus capacity enforcement
- License expiry checks
- Phone number format (Indian +91)
- Email uniqueness per role

### Audit Logging
- Log all admin approvals (merges, replacements)
- Log auth events (login, logout, failed attempts)
- Log state changes (trip, bus, driver, incident)
- Store in audit_logs table with user_id, action, before, after, timestamp

---

## 9. Caching Strategy

### Redis Keys
- `routes:{route_id}` — Route details (24h TTL)
- `buses:{bus_id}:current` — Current bus location (5m TTL)
- `trips:{trip_id}:eta` — ETA for trip (5m TTL, invalidated on GPS update)
- `user:fcm:tokens:{user_id}` — FCM device tokens (30d TTL)
- `rate_limit:{ip}:{endpoint}` — API rate limiting (1h TTL)
- `students:assigned:{route_id}` — Students on route (24h TTL, invalidated on assignment change)

---

## 10. Async Jobs (Queue)

### Redis Queue Jobs
1. `GenerateDailyTrips` — Runs daily 11:59 PM for next day's schedules
2. `CalculateETA` — On-demand or per GPS update
3. `SendBulkNotifications` — Batch FCM sends
4. `ProcessIncidentWorkflow` — Incident → Maintenance ticket → Recommendations
5. `GenerateReports` — Scheduled daily/weekly/monthly
6. `CleanupOldLocations` — Archive GPS data >30 days old
7. `SyncGoogleMapsRoutes` — Refresh cached routes

---

## 11. Testing Strategy

### Test Levels
- **Unit Tests** — Services, models, validation rules
- **Feature/Integration Tests** — API endpoints, workflows, database
- **Database Tests** — Migration integrity, constraint validation
- **Realtime Tests** — WebSocket broadcasting and subscription
- **External Service Mocks** — Google Maps, Firebase mock responses

### Test Coverage Targets
- Services: 90%+
- Models: 85%+
- Controllers: 80%+
- Critical paths: 100%

---

## 12. Deployment & DevOps

### Docker Compose Services
```yaml
services:
  api:
    image: ctms-api:latest
    depends_on: [postgres, redis, reverb]
    environment: [.env variables]
  reverb:
    image: ctms-reverb:latest
    depends_on: [redis]
  postgres:
    image: postgres:16-alpine
    volumes: [data]
  redis:
    image: redis:7-alpine
  nginx:
    image: nginx:alpine
    depends_on: [api, reverb]
    ports: [80, 443]
```

### CI/CD Pipeline
1. **Lint** — PHPStan, Laravel Pint
2. **Test** — PHPUnit, feature tests
3. **Build** — Docker image
4. **Push** → Registry
5. **Deploy** → Staging/Production

---

## 13. Implementation Checklist

### Foundation Phase
- [ ] Laravel 12 project scaffolded
- [ ] PostgreSQL migrations created
- [ ] Redis configured
- [ ] Sanctum/JWT auth implemented
- [ ] Audit logging set up
- [ ] Error handling & validation framework in place

### Domain Phase
- [ ] All Eloquent models created
- [ ] All relationships defined
- [ ] Factories & seeders created
- [ ] Base CRUD API for each entity
- [ ] Request validation rules

### Realtime Phase
- [ ] Reverb configured
- [ ] WebSocket channels defined
- [ ] GPS broadcasting working
- [ ] ETA channel broadcasting
- [ ] Notification WebSocket channel

### Integration Phase
- [ ] Google Maps service integrated
- [ ] Firebase FCM configured
- [ ] File upload service working
- [ ] Queue jobs defined & testable
- [ ] Redis cache implementation complete

### Testing Phase
- [ ] Feature tests for all endpoints
- [ ] Unit tests for services
- [ ] Database tests for migrations
- [ ] Realtime tests for channels
- [ ] External service mocks working

### Documentation Phase
- [ ] API documentation (Swagger/OpenAPI)
- [ ] Setup guide for developers
- [ ] Deployment runbook
- [ ] Architecture decision records (ADRs)

---

## 14. Technology Decisions

| Decision | Rationale |
|----------|-----------|
| **Laravel 12** | Mature, well-documented, strong community |
| **PostgreSQL** | ACID compliance, jsonb for flexible data, geospatial support |
| **Redis** | Fast caching, pub/sub for realtime, job queues |
| **Reverb** | Native Laravel WebSocket support, no external dependency |
| **Sanctum** | Built-in Laravel auth, token management, CSRF protection |
| **FCM** | Cross-platform push (iOS, Android, web), reliable |
| **Google Maps API** | Accurate routing, ETA calculation, geofencing |

---

## 15. Next Steps

1. **Initialize Laravel project** with Sanctum, Reverb, necessary packages
2. **Design & run database migrations** — follow `/docs/05-database-design.md`
3. **Implement authentication module** — JWT tokens, role-based middleware
4. **Build user management API** — User, Student, Driver, Admin CRUD
5. **Implement bus & route management** — CRUD + relationships
6. **Create trip generation logic** — Schedule → Trip conversion
7. **Build GPS tracking** — HTTP endpoint + WebSocket broadcasting
8. **Integrate Google Maps** — ETA service + caching
9. **Implement incident workflow** — Reporting → Maintenance → Approvals
10. **Add notifications** — FCM integration + multi-channel delivery
11. **Build reports** — Trip, occupancy, fleet analytics
12. **Write comprehensive tests** — Feature, unit, integration
13. **Create API documentation** — OpenAPI/Swagger
14. **Containerize & deploy** — Docker, docker-compose, CI/CD

---

**Document Version:** 1.0  
**Last Updated:** 2026-08-01  
**Status:** Planning Phase

