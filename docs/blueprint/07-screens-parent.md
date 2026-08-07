# Phase 2 — Parent App Screens `[NEW]`

22 screens. Mobile. The entire parent surface is new — the current SRS has no parent role.

Two principles govern every screen here:

1. **A parent sees exactly one thing: their own child's journey.** Not the fleet, not the
   route's other riders, not the driver's personal details, not the bus outside their child's
   service window. Every screen is scoped by a verified guardian link.
2. **The emotionally load-bearing moment is "my child got off the bus safely."** That single
   notification is what the product is judged on. Everything else is supporting.

---

## A. Home & tracking (6 screens)

### PA-01 · Parent Home `P0` `[NEW]`

**Purpose** — The status of every linked child, at a glance.
**Access** — Parent, verified links only.
**Entry** — Sign-in landing; app icon; notification tap.
**Exit** — Child tracking `PA-03` · Child detail `PA-05` · Notifications.

**Actions**
- See a card per linked child: name, route, current journey state (not started / on the bus /
  arrived), live ETA when running
- Track a child's bus
- Mark a child absent
- Read notifications and announcements
- Switch the focused child where several are linked
- Contact the transport office

**Validations** — Only verified links are shown. A pending link shows its pending state and
nothing about the child.

**States**
- *No children linked*: explains the linking process and offers to request a link — the
  first-run state for every new parent
- *Link pending verification*: explains what is being waited on and who decides
- *Outside service hours*: next scheduled journey per child
- *Child marked absent today*: stated, so a parent does not wait for a bus that will not stop
- *Multiple children on different routes*: each card is independent; no aggregate that could
  imply one child's status applies to another

---

### PA-02 · Child Selector `P2` `[NEW]`
**Actions** — Switch between linked children; set a default. Present only when more than one
link exists.

### PA-03 · Live Child Tracking `P0` `[NEW]` `FR-07` `FR-09`

**Purpose** — Watch a child's bus while it is running.
**Access** — Parent, for a verified child, **only during that child's active trip**.
**Entry** — Home; "trip started" notification.
**Exit** — Trip detail · Home.

**Actions** — See the bus on a map with route and stops; ETA to the child's stop; whether the
child has boarded; how many stops until the child's drop-off; current delay; contact the
transport office; share status with another guardian.

**Validations** — Access is bounded by the trip window. Outside it, the map is not available —
continuous location visibility of a bus carrying other people's children is not granted.

**States**
- *Before boarding*: "Bus is 3 stops away from Ananya's stop"
- *After boarding*: "Ananya boarded at 07:42 at Gandhi Nagar" — the confirmation is the
  headline, the map is secondary
- *After alighting*: journey complete with both times; live map ends here
- *Child marked absent*: no tracking; states why
- *Position stale*: age shown explicitly
- *Trip cancelled or incident*: replaced by the incident message and what is being done

**Workflows** — [F-10 Guardian Notification](09-system-flows.md#f-10).

### PA-04 · Journey Detail `P1` `[NEW]`
**Actions** — One journey in full: boarding and alighting times and stops, route taken,
delays, any incident affecting it.

### PA-05 · Child Detail `P1` `[NEW]`
**Actions** — The child's transport summary: route, stops, schedule, pass validity, attendance
summary; request a change; view history.
**Access notes** — Transport data only. A parent does not see academic records through this
product.

### PA-06 · Child Schedule `P1` `[NEW]`
**Actions** — The child's weekly timetable; upcoming journeys; changes highlighted.

---

## B. Attendance & absence (4 screens)

### PA-07 · Attendance History `P0` `[NEW]` `FR-08`
**Actions** — Boarding and alighting history by date; monthly summary; missed journeys;
filter by date range; export.
**States** — A day where the child was expected but did not board is distinguished from a day
they were marked absent — these mean very different things to a parent.

### PA-08 · Mark Child Absent `P0` `[NEW]`
**Actions** — Choose a date or range and the affected journeys; optional reason; submit;
cancel a marked absence.
**Validations** — Cutoff before departure; cannot mark absence for a running or completed
journey. Where a student has also acted, the most recent action wins and both are shown with
their actor.
**States** — *Success*: confirms that the driver's manifest and occupancy planning are updated.

### PA-09 · Absence History `P2` `[NEW]`
**Actions** — Past absences with who marked them and when.

### PA-10 · Attendance Alerts `P1` `[NEW]`
**Actions** — Configure alerts: notify if the child does not board, if they board an
unexpected bus, or if a journey is missed entirely.
**Validations** — "Did not board" fires only after the bus has left the child's stop, not on
schedule time, to avoid a false alarm every time a bus runs late.

---

## C. Communication (5 screens)

### PA-11 · Notifications `P0` `[NEW]` `FR-10`
Parent-scoped `SH-15`. The highest-traffic screen in this app. Categories: trip started, bus
approaching child's stop, **child boarded**, **child alighted**, delay, cancellation, incident,
announcement, fees.

### PA-12 · Announcements `P1` `[NEW]`
Parent-scoped `SH-16`.

### PA-13 · Contact Transport Office `P1` `[NEW]`
**Actions** — Send an enquiry with a category, attached to a child and optionally a journey;
view responses; call the office; view office hours.
**Validations** — Parents cannot contact the driver directly through the system. This is
deliberate: a driver taking calls from parents while driving is a safety hazard, and direct
contact bypasses the record.

### PA-14 · My Enquiries `P2` `[NEW]`
**Actions** — Submitted enquiries with status and responses; follow up.

### PA-15 · Report a Concern `P1` `[NEW]`
**Actions** — Raise a safety, conduct or service concern with description, attachments and
journey reference; submit; track.
**Validations** — Safety-category concerns are routed to operations immediately and
acknowledged as such.

---

## D. Requests & entitlement (4 screens)

### PA-16 · Request Route Change `P1` `[NEW]`
**Actions** — As `ST-15`, on behalf of a linked child; track; withdraw.
**Validations** — Same rules. Where both parent and student have requested, the requests are
merged rather than duplicated, and both requesters are notified of the outcome.

### PA-17 · Child's Pass `P1` `[NEW]`
**Actions** — View pass validity and status; renew; download.

### PA-18 · Payments `P1` `[NEW]`
**Actions** — Pay transport fees; view history and receipts; see outstanding dues; set up
reminders.
**States** — *Payment pending*: entitlement unchanged until confirmed.

### PA-19 · Fee Details `P2` `[NEW]`
**Actions** — Fee breakdown, concessions, payment schedule per child.

---

## E. Account & linking (3 screens)

### PA-20 · Request Child Link `P0` `[NEW]`

**Purpose** — The gate that controls all parent access. The most security-sensitive screen in
this app.
**Access** — Any authenticated parent.
**Entry** — First run; home when no children are linked; account settings.
**Exit** — Pending state; home once approved.

**Actions** — Enter the child's registration number and date of birth; state the relationship;
attach supporting documentation where the institution requires it; submit; track status;
withdraw.

**Validations**
- The claim alone never grants access. Approval requires **either** the student's own
  confirmation **or** verification by transport staff against institutional records
- Rate-limited, and repeated failed attempts against different registration numbers are
  flagged to administrators as a probing pattern
- The response is identical whether or not the registration number exists — this screen must
  not become a tool for discovering which children ride which routes

**States**
- *Pending*: what is being waited on and roughly how long
- *Approved*: the child appears on home; the student and existing guardians are notified that
  a new guardian was added
- *Rejected*: reason and the route to contact the office

**Workflows** — [F-03 Guardian Linking](09-system-flows.md#f-03).

### PA-21 · My Profile `P1` `[NEW]`
Parent-scoped `SH-09`: name, contact, relationship to each child, notification preferences,
linked children with the data classes each link grants, and the ability to revoke a link.

### PA-22 · Settings `P2` `[NEW]`
**Actions** — Notification preferences per child and per category, language, security,
sign out.
