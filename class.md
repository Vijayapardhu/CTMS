\# Campus Transport Management System (CTMS)



\## Domain Model \& Class Specification (MVP)



> Version: 1.0



\------------------------------------------------------------------------



\# 1. User (Abstract)



\## Attributes



&#x20; Field          Type          Description

&#x20; -------------- ------------- --------------------

&#x20; id             UUID          Primary Key

&#x20; firstName      String(100)   First name

&#x20; lastName       String(100)   Last name

&#x20; gender         Enum          Male/Female/Other

&#x20; dateOfBirth    Date          DOB

&#x20; email          String(150)   Unique email

&#x20; phone          String(15)    Mobile

&#x20; passwordHash   String(255)   Encrypted password

&#x20; profilePhoto   String        Image URL

&#x20; addressLine1   String        Address

&#x20; addressLine2   String        Address

&#x20; city           String        City

&#x20; state          String        State

&#x20; postalCode     String        PIN

&#x20; isActive       Boolean       Active status

&#x20; lastLogin      DateTime      Last login

&#x20; createdAt      DateTime      Created

&#x20; updatedAt      DateTime      Updated



\## Methods



\-   login()

\-   logout()

\-   changePassword()

\-   updateProfile()



\------------------------------------------------------------------------



\# 2. Student



Extends \*\*User\*\*



\## Attributes



&#x20; Field              Type

&#x20; ------------------ ---------

&#x20; studentId          String

&#x20; rollNumber         String

&#x20; admissionNumber    String

&#x20; department         String

&#x20; course             String

&#x20; year               Integer

&#x20; section            String

&#x20; semester           Integer

&#x20; busId              UUID

&#x20; routeId            UUID

&#x20; pickupStopId       UUID

&#x20; guardianName       String

&#x20; guardianPhone      String

&#x20; transportEnabled   Boolean



\## Methods



\-   viewBus()

\-   viewETA()

\-   receiveNotification()



\------------------------------------------------------------------------



\# 3. Driver



Extends \*\*User\*\*



\## Attributes



&#x20; Field                  Type

&#x20; ---------------------- --------------

&#x20; employeeId             String

&#x20; employee name          String

&#x20; drivingLicenseNumber   String

&#x20; licenseExpiry          Date

&#x20; aadhaarNumber          String

&#x20; joiningDate            Date

&#x20; emergencyContact       String

&#x20; assignedBusId          UUID

&#x20; available              Boolean

&#x20; status                 DriverStatus



\## Methods



\-   startTrip()

\-   endTrip()

\-   increasePassenger()

\-   decreasePassenger()

\-   reportIssue()

\-   sendSOS()



\------------------------------------------------------------------------



\# 4. Admin



Extends \*\*User\*\*



\## Attributes



&#x20; Field         Type

&#x20; ------------- --------

&#x20; employeeId    String

&#x20; designation   String

&#x20; officePhone   String



\## Methods



\-   assignDriver()

\-   assignBus()

\-   createRoute()

\-   approveMerge()

\-   assignReplacement()



\------------------------------------------------------------------------



\# 5. Bus



\## Attributes



&#x20; Field                Type

&#x20; -------------------- -----------

&#x20; id                   UUID

&#x20; busNumber            String

&#x20; registrationNumber   String

&#x20; chassisNumber        String

&#x20; engineNumber         String

&#x20; manufacturer         String

&#x20; model                String

&#x20; manufacturingYear    Integer

&#x20; capacity             Integer

&#x20; currentPassengers    Integer

&#x20; fuelType             String

&#x20; mileage              Decimal

&#x20; gpsEnabled           Boolean

&#x20; gpsDeviceId          UUID

&#x20; status               BusStatus

&#x20; lastServiceDate      Date

&#x20; nextServiceDate      Date

&#x20; insuranceExpiry      Date

&#x20; permitExpiry         Date



\## Methods



\-   assignDriver()

\-   assignRoute()

\-   updateStatus()



\------------------------------------------------------------------------



\# 6. Route



&#x20; Field               Type

&#x20; ------------------- ---------

&#x20; id                  UUID

&#x20; routeCode           String

&#x20; routeName           String

&#x20; source              String

&#x20; destination         String

&#x20; totalDistance       Decimal

&#x20; estimatedDuration   Integer

&#x20; active              Boolean



\------------------------------------------------------------------------



\# 7. Route Stop



&#x20; Field             Type

&#x20; ----------------- ---------

&#x20; id                UUID

&#x20; routeId           UUID

&#x20; stopName          String

&#x20; landmark          String

&#x20; latitude          Double

&#x20; longitude         Double

&#x20; sequence          Integer

&#x20; geofenceRadius    Double

&#x20; expectedArrival   Time



\------------------------------------------------------------------------



\# 8. Schedule



&#x20; Field           Type

&#x20; --------------- ---------

&#x20; id              UUID

&#x20; routeId         UUID

&#x20; busId           UUID

&#x20; dayOfWeek       Enum

&#x20; departureTime   Time

&#x20; arrivalTime     Time

&#x20; active          Boolean



\------------------------------------------------------------------------



\# 9. Trip



&#x20; Field            Type

&#x20; ---------------- ------------

&#x20; id               UUID

&#x20; scheduleId       UUID

&#x20; busId            UUID

&#x20; driverId         UUID

&#x20; routeId          UUID

&#x20; tripDate         Date

&#x20; startTime        DateTime

&#x20; endTime          DateTime

&#x20; status           TripStatus

&#x20; passengerCount   Integer

&#x20; averageSpeed     Double

&#x20; delayMinutes     Integer



\------------------------------------------------------------------------



\# 10. Trip Location



&#x20; Field       Type

&#x20; ----------- ----------

&#x20; id          UUID

&#x20; tripId      UUID

&#x20; latitude    Double

&#x20; longitude   Double

&#x20; speed       Double

&#x20; heading     Double

&#x20; accuracy    Double

&#x20; timestamp   DateTime



\------------------------------------------------------------------------



\# 11. Passenger Log



&#x20; Field              Type

&#x20; ------------------ ------------------

&#x20; id                 UUID

&#x20; tripId             UUID

&#x20; action             Enum(Board,Exit)

&#x20; countAfterAction   Integer

&#x20; timestamp          DateTime



\------------------------------------------------------------------------



\# 12. Vehicle Incident



&#x20; Field         Type

&#x20; ------------- ----------

&#x20; id            UUID

&#x20; tripId        UUID

&#x20; busId         UUID

&#x20; driverId      UUID

&#x20; issueType     String

&#x20; severity      Enum

&#x20; description   Text

&#x20; imageUrl      String

&#x20; latitude      Double

&#x20; longitude     Double

&#x20; status        Enum

&#x20; reportedAt    DateTime



\------------------------------------------------------------------------



\# 13. Maintenance Ticket



&#x20; Field                Type

&#x20; -------------------- ----------

&#x20; id                   UUID

&#x20; incidentId           UUID

&#x20; busId                UUID

&#x20; ticketNumber         String

&#x20; assignedTechnician   String

&#x20; status               Enum

&#x20; repairStart          DateTime

&#x20; repairEnd            DateTime

&#x20; estimatedCost        Decimal

&#x20; remarks              Text



\------------------------------------------------------------------------



\# 14. Bus Merge Recommendation



&#x20; Field                Type

&#x20; -------------------- ---------

&#x20; id                   UUID

&#x20; sourceTripId         UUID

&#x20; targetTripId         UUID

&#x20; sourcePassengers     Integer

&#x20; targetPassengers     Integer

&#x20; mergedPassengers     Integer

&#x20; estimatedFuelSaved   Decimal

&#x20; distanceIncrease     Decimal

&#x20; status               Enum

&#x20; approvedBy           UUID



\------------------------------------------------------------------------



\# 15. Replacement Assignment



&#x20; Field                 Type

&#x20; --------------------- ----------

&#x20; id                    UUID

&#x20; incidentId            UUID

&#x20; replacementBusId      UUID

&#x20; replacementDriverId   UUID

&#x20; etaMinutes            Integer

&#x20; assignedAt            DateTime

&#x20; status                Enum



\------------------------------------------------------------------------



\# 16. Notification



&#x20; Field        Type

&#x20; ------------ ----------

&#x20; id           UUID

&#x20; receiverId   UUID

&#x20; title        String

&#x20; message      Text

&#x20; type         Enum

&#x20; isRead       Boolean

&#x20; sentAt       DateTime



\------------------------------------------------------------------------



\# 17. Announcement



&#x20; Field         Type

&#x20; ------------- ----------

&#x20; id            UUID

&#x20; title         String

&#x20; description   Text

&#x20; audience      Enum

&#x20; publishAt     DateTime

&#x20; expireAt      DateTime



\------------------------------------------------------------------------



\# Enums



\## UserRole



\-   ADMIN

\-   DRIVER

\-   STUDENT



\## BusStatus



\-   AVAILABLE

\-   RUNNING

\-   MAINTENANCE

\-   BREAKDOWN

\-   OFFLINE



\## DriverStatus



\-   AVAILABLE

\-   ON\_TRIP

\-   LEAVE

\-   OFF\_DUTY



\## TripStatus



\-   SCHEDULED

\-   RUNNING

\-   COMPLETED

\-   CANCELLED



\------------------------------------------------------------------------



\# Relationships



\-   User \\<\\|-- Student

\-   User \\<\\|-- Driver

\-   User \\<\\|-- Admin

\-   Route 1..\\\* RouteStop

\-   Route 1..\\\* Schedule

\-   Bus 1..\\\* Trip

\-   Driver 1..\\\* Trip

\-   Trip 1..\\\* TripLocation

\-   Trip 1..\\\* PassengerLog

\-   Trip 1..\\\* VehicleIncident

\-   VehicleIncident 1..1 MaintenanceTicket

\-   Student \\\*..1 Route

\-   Student \\\*..1 Bus



\------------------------------------------------------------------------



\# Notes





