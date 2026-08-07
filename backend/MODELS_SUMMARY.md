# CTMS Backend Laravel Eloquent Models

All 18 models have been successfully created in the `app/Models` directory. Each model uses UUID as the primary key using the `HasUuids` trait and includes comprehensive relationships, casts, and methods.

## Models Overview

### 1. **User.php** - Base User Model
- **Purpose**: Abstract base class for polymorphic relationships with Laravel Sanctum authentication
- **Key Features**:
  - Implements `Authenticatable` for login
  - HasApiTokens trait for Sanctum authentication
  - Relationships: student (hasOne), driver (hasOne), admin (hasOne)
  - Methods: `getFullName()`, `isAdmin()`, `isDriver()`, `isStudent()`, `updateLoginAt()`

### 2. **Student.php** - Student Profile
- **Purpose**: Represents student users in the system
- **Key Features**:
  - Tracks registration number, department, year of study
  - Manages ticket validity (has_valid_ticket, ticket_expiry_date)
  - Relationships: user (belongsTo), bus, route, trips (hasManyThrough), passengerLogs (hasMany)
  - Methods: `hasValidTicket()`, `getDepartmentAttribute()`

### 3. **Driver.php** - Driver Profile
- **Purpose**: Represents driver users in the system
- **Key Features**:
  - Manages license information and validity
  - Tracks GPS location and last update timestamp
  - Relationships: user (belongsTo), trips (hasMany), maintenanceTickets (hasMany), vehicleIncidents (hasMany)
  - Methods: `isLicenseValid()`, `updateCurrentLocation()`, `getAverageRating()`

### 4. **Admin.php** - Admin Profile
- **Purpose**: Represents admin users in the system
- **Key Features**:
  - Manages designation, department, and access levels
  - Stores permissions as JSON array
  - Relationships: user (belongsTo), createdAnnouncements (hasMany)

### 5. **Bus.php** - Vehicle Model
- **Purpose**: Represents buses in the fleet
- **Key Features**:
  - Tracks registration, model, seating capacity, status, mileage
  - Relationships: trips (hasMany), schedules (hasMany), tripLocations (hasManyThrough), incidents (hasMany), maintenanceTickets (hasMany)
  - Methods: `isAvailable()`, `updateStatus()`, `getMileageMetrics()`

### 6. **Route.php** - Route Definition
- **Purpose**: Defines bus routes with stops and timing
- **Key Features**:
  - Stores route details (name, code, distance, estimated duration)
  - Relationships: stops (hasMany, as RouteStop), schedules (hasMany), trips (hasMany)
  - Methods: `getStopsInOrder()`, `calculateETA()`

### 7. **RouteStop.php** - Stop on Route
- **Purpose**: Represents individual stops along a route
- **Key Features**:
  - Tracks sequence, GPS coordinates, and stop type
  - Relationships: route (belongsTo), passengerLogs (hasMany)
  - Methods: `getAddress()`, `getCoordinates()`

### 8. **Schedule.php** - Bus Schedule
- **Purpose**: Defines recurring schedules for routes
- **Key Features**:
  - Stores departure/arrival times, day of week, frequency
  - Nullable bus and driver assignments
  - Relationships: route (belongsTo), bus (belongsTo), driver (belongsTo), trips (hasMany)
  - Methods: `isActiveToday()`, `generateTripsForDate()`

### 9. **Trip.php** - Individual Trip Instance
- **Purpose**: Represents a single trip execution
- **Key Features**:
  - Tracks trip status, date, occupancy, GPS location
  - Scopes: `active()`, `byDate()`, `byBus()`
  - Relationships: schedule, bus, driver, route (belongsTo), locations (hasMany), passengerLogs (hasMany)
  - Methods: `isRunning()`, `updateStatus()`, `getCurrentOccupancy()`, `updateLocation()`

### 10. **TripLocation.php** - GPS Track Point
- **Purpose**: Stores GPS coordinates during trips
- **Key Features**:
  - Time-series data for trip tracking
  - Optimized for (trip_id, recorded_at) queries
  - Relationships: trip (belongsTo)

### 11. **PassengerLog.php** - Boarding/Alighting Record
- **Purpose**: Tracks when students board and alight from trips
- **Key Features**:
  - Action types: BOARDED, ALIGHTED
  - Scopes: `boarded()`, `alighted()`
  - Relationships: trip, student, routeStop (belongsTo)

### 12. **VehicleIncident.php** - Incident Report
- **Purpose**: Records vehicle incidents and accidents
- **Key Features**:
  - Tracks severity and resolution status
  - Scopes: `unresolved()`, `critical()`
  - Relationships: trip, driver, bus, reportedBy (belongsTo User)
  - Methods: `markResolved()`, `isCritical()`

### 13. **MaintenanceTicket.php** - Maintenance Request
- **Purpose**: Manages vehicle maintenance tasks
- **Key Features**:
  - Tracks issue description, priority, scheduled and completion dates
  - Scopes: `open()`, `completed()`
  - Relationships: bus (belongsTo), assignedTo (belongsTo Driver)
  - Methods: `markComplete()`

### 14. **ReplacementAssignment.php** - Bus Replacement
- **Purpose**: Records when a bus is replaced for a trip
- **Key Features**:
  - Tracks original bus, replacement bus, reason, timing
  - Relationships: originalBus, replacementBus, trip, createdBy (belongsTo User)
  - Methods: `getDuration()`, `isActive()`

### 15. **BusMergeRecommendation.php** - Fleet Optimization
- **Purpose**: Recommends merging routes of similar buses
- **Key Features**:
  - Tracks recommendations with estimated savings
  - Scopes: `pending()`, `approved()`
  - Relationships: bus1, bus2, recommendedBy, decisionBy (belongsTo User)
  - Methods: `approve()`, `reject()`

### 16. **Notification.php** - User Notification
- **Purpose**: System notifications for users
- **Key Features**:
  - Stores title, message, type, and custom data as JSON
  - Tracks read status
  - Scopes: `unread()`, `read()`
  - Relationships: user (belongsTo)
  - Methods: `markAsRead()`

### 17. **Announcement.php** - System Announcement
- **Purpose**: Broadcasting announcements to users
- **Key Features**:
  - Target audience selection (students, drivers, all)
  - Priority levels and expiration dates
  - Scopes: `active()`, `forAudience()`
  - Relationships: createdBy (belongsTo User)
  - Methods: `isExpired()`, `isPublished()`

### 18. **AuditLog.php** - Audit Trail
- **Purpose**: Records all significant system changes
- **Key Features**:
  - Tracks action, table, old values, new values
  - Stores IP address and user agent for security
  - Only has created_at (immutable)
  - Scopes: `byUser()`, `byTable()`, `byAction()`
  - Relationships: user (belongsTo)

## Key Features Across All Models

✅ **UUID Primary Keys**: All models use `HasUuids` trait for database-independent IDs
✅ **Type Safety**: Comprehensive casts for date, float, integer, boolean, and array types
✅ **Relationships**: Properly configured with foreign keys and appropriate relationship types
✅ **Business Logic**: Each model includes relevant methods for domain operations
✅ **Query Scopes**: Predefined scopes for common filtering operations
✅ **Comments**: Thoughtful inline comments for complex logic
✅ **Mass Assignment**: Fillable arrays configured for security
✅ **Timestamps**: Proper timestamp handling (created_at, updated_at)
✅ **Laravel Sanctum**: User model ready for API authentication

## Usage Example

```php
// Create a new student
$student = Student::create([
    'user_id' => $userId,
    'registration_number' => 'STU001',
    'department' => 'Engineering',
    'year_of_study' => 2,
]);

// Check if student has valid ticket
if ($student->hasValidTicket()) {
    // Allow boarding
}

// Get all trips for a date
$trips = Trip::byDate(now())->active()->get();

// Mark notification as read
$notification->markAsRead();

// Create audit log
AuditLog::create([
    'user_id' => auth()->id(),
    'action' => 'create',
    'table_name' => 'buses',
    'record_id' => $bus->id,
    'new_values' => $bus->toArray(),
]);
```

## Database Relationships Map

```
User (1) ──→ (1) Student
User (1) ──→ (1) Driver
User (1) ──→ (1) Admin

Student (1) ──→ (M) PassengerLog
Trip (1) ──→ (M) PassengerLog
RouteStop (1) ──→ (M) PassengerLog

Schedule (1) ──→ (M) Trip
Bus (1) ──→ (M) Trip
Driver (1) ──→ (M) Trip
Route (1) ──→ (M) Trip

Trip (1) ──→ (M) TripLocation
Trip (1) ──→ (M) VehicleIncident

Route (1) ──→ (M) RouteStop
Route (1) ──→ (M) Schedule

Bus (1) ──→ (M) MaintenanceTicket
Driver (1) ──→ (M) MaintenanceTicket (assigned_to)

Bus (1) ──→ (M) VehicleIncident
Driver (1) ──→ (M) VehicleIncident

User (1) ──→ (M) Notification
User (1) ──→ (M) Announcement (created_by)
User (1) ──→ (M) AuditLog

BusMergeRecommendation:
  - bus1 ──→ Bus
  - bus2 ──→ Bus
  - recommendedBy ──→ User
  - decisionBy ──→ User (nullable)

ReplacementAssignment:
  - originalBus ──→ Bus
  - replacementBus ──→ Bus
  - trip ──→ Trip
  - createdBy ──→ User
```

## Next Steps

1. Create corresponding migration files if not already present
2. Run migrations: `php artisan migrate`
3. Generate model factories for testing
4. Create model tests
5. Define API routes and controllers
6. Set up request validation using Form Requests
