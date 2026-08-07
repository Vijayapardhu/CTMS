# Phase 2 — Student App Screens

29 screens. Mobile, with a reduced web equivalent. The dominant use is a thirty-second glance
at a bus stop in the morning: *where is my bus and will I be late?* Everything else is
secondary.

---

## A. Home & tracking (7 screens)

### ST-01 · Student Home `P0`

**Purpose** — Answer "what is happening with my transport right now" without any navigation.
**Access** — Student, own data only.
**Entry** — Sign-in landing; app icon; notification tap.
**Exit** — Live tracking `ST-02` · Schedule `ST-08` · Notifications · Profile.

**Actions**
- See the next trip: route, stop, scheduled time and live ETA when the bus is running
- Track the bus (the primary action during service hours)
- Mark oneself absent for an upcoming trip
- View today's schedule and pass status
- Read announcements and unread notifications
- Report a problem

**Validations** — None (read surface).

**States**
- *During service, bus running*: live ETA and a map preview dominate the screen
- *During service, bus not yet started*: scheduled time plus "not started yet" — explicitly,
  because silence reads as a fault to a student standing in the rain
- *Outside service hours*: tomorrow's schedule
- *No transport assigned*: explains that transport must be assigned by the office, and offers
  the request path — the single most common state for a new student, and it must not look
  like an error
- *Pass expired*: prominent, with the renewal path, shown before the student is refused at a
  bus door
- *Service suspended*: the reason from the service calendar

**Workflows** — [F-08 Student Journey](09-system-flows.md#f-08).

---

### ST-02 · Live Bus Tracking `P0` `FR-07` `FR-09`

**Purpose** — Watch the assigned bus approach.
**Access** — Student with an active assignment, **only while their own trip is running**.
**Entry** — Home; "bus started" notification; the schedule.
**Exit** — Trip detail · Stop detail · Home.

**Actions** — See the bus on a map with the route and stops; see the ETA to their own stop;
see how many stops away it is; see current delay; centre on the bus or on their stop; view
occupancy indicator ("filling up"); share a live status with a guardian; set an arrival
reminder.

**Validations** — Access is scoped to the trip serving this student's assignment. A student
cannot view arbitrary buses by identifier.

**States**
- *Bus not started*: countdown to scheduled departure; the map shows the route without a bus
- *Live*: position animates; ETA updates; last-updated age is visible
- *Position stale*: explicitly labelled "last seen 4 minutes ago" — never a stale dot
  presented as current
- *ETA unavailable* (routing provider down): fall back to schedule-based estimate, clearly
  labelled as estimated
- *Trip completed*: switches to a summary and the next trip
- *Trip cancelled*: replaces the map with the reason and any alternative offered
- *Offline*: last known state with its age, and a note that live updates need a connection

---

### ST-03 · Trip Detail `P1` `FR-06`
**Actions** — Full stop-by-stop view of the current or a past trip with planned and actual
times, the student's own boarding record, and delay; report a problem with this trip.

### ST-04 · My Stop `P1` `FR-05`
**Actions** — The assigned pickup stop on a map, with walking directions from the current
location, landmark description, scheduled times, and historical punctuality for that stop.

### ST-05 · Route Map `P1` `FR-05`
**Actions** — The whole assigned route with all stops, timings and distances. Available offline.

### ST-06 · Nearby Stops `P2` `[NEW]`
**Actions** — Stops near the current location across all routes, with the next service at each.
Useful when a student is not at home. Read-only; it does not permit boarding elsewhere without
a request.

### ST-07 · Arrival Reminder `P2` `[NEW]`
**Actions** — Set a reminder at N minutes before the bus reaches the stop; manage reminders.
**Validations** — Reminder lead time within a sensible range; delivered on the device, which
means it works even where the network does not.

---

## B. Schedule & attendance (6 screens)

### ST-08 · My Schedule `P0` `FR-05`
**Actions** — Weekly timetable for the assigned route; upcoming trips; changes highlighted;
mark absence for a future trip; export to device calendar.
**States** — Changes to the timetable since last view are flagged.

### ST-09 · Mark Absence `P1` `[NEW]`
**Purpose** — Tell the system in advance that a seat will be empty.
**Actions** — Choose a date or range and the affected trips; give an optional reason; submit;
cancel a previously marked absence.
**Validations** — Cannot mark absence for a trip already running or completed; a cutoff
applies before departure, after which the count is fixed for planning.
**States** — *Success*: confirms that occupancy planning has been updated and guardians
notified where a link exists.

### ST-10 · My Attendance `P1` `FR-08`
**Actions** — History of boarding and alighting by date, with stop and time; monthly summary;
filter by date range; report a discrepancy.
**Validations** — A reported discrepancy raises a request to staff; the student cannot amend
the record.

### ST-11 · Attendance Detail `P2` `FR-08`
**Actions** — One day's detail: trip, bus, driver, boarding and alighting stops and times.

### ST-12 · Trip History `P2`
**Actions** — Past journeys with date, route, duration and punctuality.

### ST-13 · Service Calendar `P2` `[NEW]`
**Actions** — Which days the service runs; holidays; suspensions; special timetables.

---

## C. Transport & entitlement (7 screens)

### ST-14 · My Transport `P0` `FR-04`
**Actions** — View the assigned route, pickup and drop-off stops, and pass validity; request a
change; view assignment history.
**States** — *Unassigned*: explains the process and offers the request action.

### ST-15 · Request Route Change `P1` `[NEW]`
**Actions** — Choose the desired route and stop, give a reason and a preferred effective date,
attach supporting documents where required, submit; track status; withdraw.
**Validations** — The requested stop must belong to the requested route; the target route must
have capacity (shown before submission, so the student is not queuing for something full); one
open request at a time.
**States** — *Pending*: expected decision time. *Rejected*: reason shown, with the option to
submit a different request.

### ST-16 · My Pass `P0` `[NEW]`
**Actions** — View pass status, validity, route and QR or code for inspection; renew; download
or print.
**States** — *Expiring* warns with days remaining. *Expired* is prominent and explains the
consequence for boarding.

### ST-17 · Renew Pass `P1` `[NEW]`
**Actions** — Select a term, see the fee, choose a payment method, pay, receive confirmation.
**Validations** — Renewal is refused for suspended student records with an explanation.
**States** — *Payment pending*: entitlement unchanged until confirmed, stated plainly.

### ST-18 · Payments & Receipts `P1` `[NEW]`
**Actions** — Payment history, receipts, outstanding dues; download receipts; pay dues.

### ST-19 · Fee Details `P2` `[NEW]`
**Actions** — Breakdown of the fee for the assigned route, concessions applied, and the
payment schedule.

### ST-20 · My Guardians `P1` `[NEW]`
**Actions** — View linked guardians and what each can see; invite a guardian; approve or
refuse a guardian's link request; revoke a link.
**Validations** — The student's own approval is one of the accepted verification paths for a
guardian link. Revocation takes effect immediately.
**States** — *Pending request*: shown prominently with approve and refuse actions, since an
unreviewed request means an adult is waiting on access to a minor's location.

---

## D. Communication & support (6 screens)

### ST-21 · Notifications `P0` `FR-10`
Student-scoped `SH-15`: trip started, bus approaching, delay, cancellation, replacement,
attendance confirmation, announcements, pass and payment notices.

### ST-22 · Announcements `P1` `FR-10`
Student-scoped `SH-16`.

### ST-23 · Report a Problem `P1` `[NEW]`
**Actions** — Choose a category (bus did not arrive, overcrowding, driver conduct, cleanliness,
safety, app fault); describe; attach a photograph; link to a specific trip; submit; track.
**Validations** — Category and description required. A **safety** category is routed to
operations immediately rather than into a general queue, and the student is told so.

### ST-24 · My Reports `P2` `[NEW]`
**Actions** — Submitted problems with status and resolution; add follow-up.

### ST-25 · Trip Feedback `P2` `[NEW]`
**Actions** — Rate a completed trip on punctuality, comfort and driving; optional comment;
optionally anonymous.
**Validations** — One rating per trip. Anonymity is honoured in reporting — an anonymous
rating must not be re-identifiable through the interface.

### ST-26 · Lost & Found `P2` `[NEW]`
**Actions** — Report an item lost on a specific trip; browse found items; claim.

---

## E. Personal (3 screens)

### ST-27 · My Profile `P0`
Student-scoped `SH-09`, plus registration number, department, year, hostel and emergency
contact. Academic and registration fields are read-only with a correction-request path.

### ST-28 · Emergency Contacts `P0`
**Actions** — View and edit own emergency contacts; view transport office and helpline numbers.
**States** — Institution helpline numbers are available offline and without a session.

### ST-29 · Settings `P2`
**Actions** — Notification preferences, language, map preferences, location permission,
security, sign out.
