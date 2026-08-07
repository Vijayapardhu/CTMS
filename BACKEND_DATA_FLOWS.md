# Backend Data Flow & Workflow Diagrams

## Campus Transport Management System (CTMS) - Key Flows

---

## 1. Daily Trip Workflow

```
Scheduled Job (11:59 PM)
    ↓
TripGenerator::generate() [Daily]
    ├─ Query all schedules for next day
    ├─ Filter active routes & buses
    ├─ Assign driver from available drivers
    ├─ Create Trip records (status: SCHEDULED)
    └─ Emit: TripsGenerated event
        ↓
    Students see trips in app
    Driver receives trip assignment
```

---

## 2. Real-time GPS Tracking Flow

```
Driver App (every 5-10s)
    ├─ Capture GPS coordinates (lat, lon, speed, heading)
    ├─ POST /api/v1/gps/location {trip_id, lat, lon, speed, ...}
    └─→ API
        ├─ Validate coordinates (geofence check)
        ├─ Store in trip_locations table
        ├─ Update trip's current_location cache
        ├─ Emit: GPSLocationReceived event
        └─→ Listener: SendGPSBroadcast
            ├─ Format location: {trip_id, lat, lon, speed, timestamp}
            ├─ Broadcast via: private-trips.{trip_id}.gps
            └─→ WebSocket
                └─→ Student App receives live position
                    ├─ Update map marker
                    ├─ Calculate distance to next stop
                    └─ Show "Bus approaching in X min"

Simultaneously:
    ├─ ETAService::calculate() triggers
    │   ├─ Call Google Maps Routes API
    │   ├─ Cache result for 5 min
    │   └─ Store estimated arrival times per stop
    │
    ├─ Listener: SendETAUpdate
    │   ├─ Calculate delay (actual vs scheduled)
    │   └─ Broadcast: private-trips.{trip_id}.updates
    │       └─→ Student App shows ETA + delay
    │
    └─ Check geofence entry/exit
        ├─ If entering stop geofence
        │   ├─ Set stop_id in current_location
        │   ├─ Emit: BusNearingStop event
        │   └─ NotificationService sends "Bus near your stop"
        │
        └─ If exiting stop geofence unexpectedly
            ├─ Log anomaly
            └─ Alert admin if critical
```

---

## 3. Passenger Counting Workflow

```
Driver App (at each stop)
    │
    ├─ Student boards bus
    │   └─ Driver taps "+1" button
    │       └─→ API: POST /api/v1/trips/{id}/passengers/board
    │           ├─ Get trip (query from cache)
    │           ├─ Check bus capacity (bus.capacity vs current_passengers)
    │           ├─ If capacity OK:
    │           │   ├─ Increment passenger count
    │           │   ├─ Create PassengerLog {trip_id, action: BOARD, count_after, timestamp}
    │           │   ├─ Update trip cache
    │           │   ├─ Emit: PassengerCountChanged event
    │           │   └─ Broadcast update to trip channel
    │           └─ Else: Return BusFullException (409 error)
    │
    └─ Student alights from bus
        └─ Driver taps "-1" button
            └─→ API: POST /api/v1/trips/{id}/passengers/alight
                ├─ Decrement passenger count
                ├─ Create PassengerLog {trip_id, action: ALIGHT, count_after, timestamp}
                ├─ Emit: PassengerCountChanged event
                └─ Broadcast update

Occupancy Report [Daily]
    ├─ Query all passenger logs for trip
    ├─ Calculate: (max_passengers / capacity) × 100
    └─ Store occupancy metric in reports
```

---

## 4. Incident Reporting & Workflow

```
Driver App (during trip)
    └─ Driver experiences issue (breakdown, tyre, accident)
        └─→ API: POST /api/v1/incidents {type, severity, description, location, photo}
            ├─ Store photo via FileUploadService (S3/local)
            ├─ Create VehicleIncident record
            ├─ Set status: OPEN
            ├─ Emit: IncidentReported event
            └─→ Listener: CreateMaintenanceTicket
                ├─ Create MaintenanceTicket {incident_id, bus_id, status: PENDING}
                └─ NotificationService sends alert to maintenance team

            └─→ Listener: RecommendReplacementBus
                ├─ Call ReplacementService::recommend()
                │   ├─ Query available buses near incident location
                │   ├─ Calculate ETA to incident from each
                │   ├─ Rank by ETA (fastest first)
                │   └─ Create BusReplacementRecommendation records
                │
                └─ Emit: RecommendationAvailable event
                    └─ NotificationService alerts Admin
                        └─ Admin Dashboard shows:
                            ├─ Incident details + photo
                            ├─ Replacement recommendations sorted by ETA
                            └─ Approve button triggers:
                                └─→ API: POST /incidents/{id}/replacements/{rec_id}/approve
                                    ├─ Admin authentication + authorization
                                    ├─ Create ReplacementAssignment
                                    ├─ Update bus status: ASSIGNED
                                    ├─ Assign new driver to bus
                                    ├─ Create new Trip for replacement
                                    ├─ Emit: ReplacementApproved event
                                    │   └─ NotificationService:
                                    │       ├─ Notify replacement driver
                                    │       ├─ Notify affected students (route change)
                                    │       └─ Broadcast trip update
                                    └─ Audit log: admin_id, action, timestamp

Current trip:
    ├─ Status: DELAYED
    ├─ Students notified via FCM + in-app
    └─ Wait for replacement to arrive
        └─ Once replacement at incident location:
            ├─ Driver 1 alights all passengers to Driver 2's bus
            ├─ API: PATCH /trips/{original}/status COMPLETED
            ├─ API: PATCH /trips/{replacement}/status RUNNING
            └─ Passenger logs transferred
```

---

## 5. Bus Merge (Low-Occupancy) Workflow

```
Scheduled Job [Every 30 min during operation hours]
    └─ MergeService::findMergeCandidates()
        ├─ Query all RUNNING trips
        ├─ For each trip: occupancy = (passengers / capacity)
        ├─ Find trips with occupancy < 30%
        ├─ For each candidate pair:
        │   ├─ Calculate fuel saved if merged (distance optimization)
        │   ├─ Verify target bus has capacity for source passengers
        │   ├─ Create BusMergeRecommendation {source_trip, target_trip, fuel_saved, status: PENDING}
        │   └─ Emit: MergeRecommendationCreated event
        │
        └─→ NotificationService alerts Admin
            └─ Admin Dashboard shows:
                ├─ Recommended merges sorted by fuel savings
                ├─ Occupancy details for both trips
                └─ Approve button triggers:
                    └─→ API: POST /trips/merges/{rec_id}/approve
                        ├─ Admin auth + authorization + audit log
                        ├─ Update BusMergeRecommendation: status = APPROVED
                        ├─ Emit: MergeApproved event
                        │   ├─ NotificationService notifies:
                        │   │   ├─ Driver of source trip
                        │   │   ├─ Driver of target trip
                        │   │   └─ All students on source trip (redirect to target bus)
                        │   │
                        │   └─ Broadcast trip updates via WebSocket
                        │
                        ├─ Create Job: ProcessMerge (async)
                        │   ├─ Divert source bus to target trip
                        │   ├─ Transfer source passengers to target bus
                        │   ├─ Create new trip record for target with merged passengers
                        │   └─ Mark source trip: status = MERGED_INTO_TRIP_X
                        │
                        └─ Return merge confirmation to admin
```

---

## 6. ETA Service Integration

```
Student App (viewing trip details)
    └─ GET /api/v1/trips/{trip_id}/eta
        ├─ Check Redis cache: trips:{trip_id}:eta
        ├─ If cached and fresh (< 5 min):
        │   └─ Return cached result immediately
        │
        └─ Else (cache miss or stale):
            └─ Call ETAService::calculate()
                ├─ Get current GPS location (trip_locations, order by timestamp DESC limit 1)
                ├─ Get next unvisited stop (route_stops where sequence > current)
                ├─ Call Google Maps Routes API:
                │   ├─ origin: current_location
                │   ├─ destination: next_stop_location
                │   ├─ departure_time: now
                │   ├─ traffic_model: best_guess
                │   └─ Returns: duration_in_seconds, distance_in_meters
                │
                ├─ Calculate ETA = now + duration
                ├─ Cache in Redis (5 min TTL)
                ├─ Store in DB for historical analysis
                └─ Return: {stop_id, eta_time, delay_minutes_vs_schedule}

            └─ (Async) Broadcast to WebSocket:
                ├─ Channel: private-trips.{trip_id}.updates
                ├─ Event: {type: 'eta_updated', stop_id, eta_time, delay}
                └─ Students see real-time ETA updates on map

Real-time GPS updates trigger ETA recalculation:
    ├─ On each GPSLocationReceived event
    ├─ Emit UpdateETA job (async)
    └─ Calculate and broadcast new ETA
```

---

## 7. Notification Multi-Channel Flow

```
Backend Event (e.g., TripStarted, BusNearingStop, IncidentReported)
    └─→ NotificationService::send()
        ├─ Build notification payload
        │   ├─ title: "Your bus has started"
        │   ├─ message: "Route 101 departs in 5 minutes"
        │   ├─ type: "TRIP_START"
        │   ├─ action: {trip_id, url: "/trips/{trip_id}"}
        │   └─ icon: "trip_icon.png"
        │
        ├─ Create Notification DB record:
        │   └─ {receiver_id, title, message, type, is_read: false, sent_at}
        │
        ├─ Send via FCM (Firebase Cloud Messaging):
        │   ├─ Get FCM tokens from cache: user:fcm:tokens:{user_id}
        │   ├─ Call Firebase API with payload
        │   ├─ Handle failures (invalid tokens, etc)
        │   └─ Emit: NotificationSentFCM event (for audit)
        │
        ├─ Broadcast via WebSocket:
        │   ├─ Channel: private-user.{user_id}.notifications
        │   ├─ Event: {title, message, type, action_url}
        │   └─ App receives in-app notification instantly
        │
        └─ (Optional) Send SMS/Email:
            ├─ Check user notification preferences
            ├─ If SMS enabled: call SMS gateway
            └─ If Email enabled: queue email job

Student App (receives notification):
    ├─ FCM push notification (phone background/foreground)
    │   └─ User taps notification → navigate to trip details
    │
    └─ In-app WebSocket notification
        ├─ Shows notification banner (unobtrusive)
        └─ User can dismiss or tap → navigate to action URL
```

---

## 8. Admin Approval Audit Trail

```
Admin Dashboard
    └─ View pending approval: Replacement / Merge / Incident
        └─ Admin reviews details (occupancy, fuel savings, photos, etc)
            └─ Click "Approve" or "Reject"
                └─→ API: POST /incidents/{id}/replacements/{id}/approve
                    ├─ Extract auth token → admin_id
                    ├─ Verify admin role (ADMIN) via RoleAuthorize middleware
                    ├─ Retrieve recommendation from DB
                    ├─ Perform approval logic (create assignment, update trip, etc)
                    ├─ Create AuditLog record:
                    │   ├─ admin_id: {admin_id}
                    │   ├─ action: "REPLACE_BUS_APPROVED"
                    │   ├─ entity_type: "replacement_assignment"
                    │   ├─ entity_id: {assignment_id}
                    │   ├─ changes: {before: PENDING, after: APPROVED}
                    │   ├─ ip_address: {request IP}
                    │   ├─ user_agent: {browser info}
                    │   └─ timestamp: now
                    │
                    ├─ Emit: ApprovalGranted event
                    ├─ Trigger notifications (driver, students, etc)
                    └─ Return: {status: 200, message: "Approved"}

Audit Report:
    ├─ Admin can query AuditLog table:
    │   └─ SELECT * FROM audit_logs WHERE admin_id = X AND date >= last_week
    ├─ See all actions taken, by whom, when, and what changed
    └─ Export for compliance / governance
```

---

## 9. Report Generation Pipeline

```
Scheduled Job [Daily 11:00 PM]
    ├─ Job: GenerateReports
    │   ├─ Collects data from today's trips
    │   ├─ Generates multiple reports:
    │   │   ├─ ReportService::generateTripReport()
    │   │   │   ├─ Query trips for date
    │   │   │   ├─ Aggregate: total trips, completed, cancelled
    │   │   │   ├─ Calculate: average delay, on-time percentage
    │   │   │   └─ Store report in reports table
    │   │   │
    │   │   ├─ ReportService::generateOccupancyReport()
    │   │   │   ├─ Query passenger_logs for all trips
    │   │   │   ├─ Calculate: avg occupancy, peak occupancy, underutilized trips
    │   │   │   └─ Generate CSV data
    │   │   │
    │   │   ├─ ReportService::generateFleetReport()
    │   │   │   ├─ Query bus status changes
    │   │   │   ├─ Calculate: availability, maintenance ratio, idle time
    │   │   │   └─ Storage in reports table
    │   │   │
    │   │   └─ ReportService::generateIncidentReport()
    │   │       ├─ Query incidents for date
    │   │       ├─ Aggregate by severity, type
    │   │       ├─ Calculate: MTTR (mean time to repair)
    │   │       └─ Identify trends
    │   │
    │   └─ Emit: ReportsGenerated event
    │       └─ NotificationService sends "Reports ready" to admin
    │
    └─ Admin Dashboard
        └─ GET /api/v1/reports/trips?date_from=&date_to=&format=json|csv|pdf
            ├─ Query reports table with filters
            ├─ Format output (JSON / CSV / PDF)
            └─ Return to admin for download/analysis

Reports can also be generated on-demand:
    └─ Admin: GET /reports/occupancy?from=2026-07-01&to=2026-07-31
        └─ Trigger ad-hoc report generation
```

---

## 10. Authentication & Token Refresh

```
Client App (first time)
    └─→ POST /api/v1/auth/login {email, password}
        ├─ Query users table: WHERE email = ?
        ├─ Hash provided password, compare with stored hash
        ├─ If match:
        │   ├─ Get user roles (query polymorphic relationship)
        │   ├─ Generate JWT token:
        │   │   ├─ header: {alg: "HS256", typ: "JWT"}
        │   │   ├─ payload: {user_id, email, role, permissions, exp: now + 1h}
        │   │   └─ signature: HMAC-SHA256(header.payload, secret)
        │   │
        │   ├─ Generate refresh token (longer TTL, e.g., 7 days)
        │   ├─ Store refresh token in DB: user_sessions {user_id, token_hash, expires_at}
        │   ├─ Create AuditLog: {user_id, action: LOGIN, ip_address, user_agent}
        │   └─ Return: {access_token, refresh_token, expires_in}
        │
        └─ Else: Return 401 Unauthorized (invalid credentials)

Subsequent API Requests:
    ├─ Client includes: Authorization: Bearer {access_token}
    ├─→ Middleware: AuthenticateRequest
    │   ├─ Extract token from header
    │   ├─ Verify JWT signature
    │   ├─ Check expiration (exp claim)
    │   ├─ Extract user_id, role
    │   ├─ Load user from DB (cache if possible)
    │   └─ Attach to request context
    │
    └─ Controller receives authenticated request
        └─ RoleAuthorize middleware checks permissions
            ├─ If user.role matches required role: ✓ proceed
            └─ Else: Return 403 Forbidden

Token Expiration (after 1 hour):
    ├─ Client gets 401 Unauthorized
    ├─→ POST /api/v1/auth/refresh {refresh_token}
    │   ├─ Verify refresh token
    │   ├─ Check expiration in user_sessions table
    │   ├─ If valid: generate new access_token + refresh_token
    │   └─ Return new tokens
    │
    └─ Client retries original request with new token

Logout:
    ├─ Client: POST /api/v1/auth/logout {refresh_token}
    ├─→ Backend:
    │   ├─ Delete refresh token from user_sessions
    │   ├─ Invalidate access token (add to blacklist in Redis)
    │   ├─ Create AuditLog: {user_id, action: LOGOUT}
    │   └─ Return 200 OK
    │
    └─ Client clears local tokens
```

---

**Document Version:** 1.0  
**Last Updated:** 2026-08-01  
**Status:** Architecture Reference

