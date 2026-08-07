# Phase 5 — System Map

The whole application in one place: modules, screens, hierarchy, transitions, dependencies and
shared components.

---

## 1. Module map

```
CTMS
│
├── 1. IDENTITY & ACCESS ─────────────────────────── SH-01..SH-13, AD-104..AD-105, AD-111
│      Authentication · Sessions · Roles · Permissions · MFA · Impersonation
│
├── 2. CONFIGURATION ─────────────────────────────── AD-106..AD-107, AD-61..AD-62, AD-96
│      Institution settings · Service calendar · Integrations · Policy
│
├── 3. FLEET ──────────────────────────────────────── AD-12..AD-24
│      ├── Buses ·  Register · Status · Capacity
│      ├── Documents · Fitness · Insurance · Permit · Tax
│      ├── Fuel · Consumption · Anomalies
│      └── Inspections · Pre-trip checks
│
├── 4. WORKFORCE ──────────────────────────────────── AD-25..AD-34, DR-21..DR-25
│      ├── Drivers · Licence · Compliance · Performance
│      ├── Roster · Duty assignment · Publication
│      └── Leave · Requests · Duty hours
│
├── 5. PEOPLE ─────────────────────────────────────── AD-35..AD-48, PA-20, ST-20
│      ├── Students · Records · Status
│      ├── Parents · Links · Verification          [NEW]
│      └── Staff · Accounts · Invitations
│
├── 6. NETWORK ────────────────────────────────────── AD-49..AD-62
│      ├── Routes · Definition · Status
│      ├── Stops · Sequence · Geofence · Library
│      └── Schedules · Timetable · Variants · Conflicts
│
├── 7. ASSIGNMENT ─────────────────────────────────── AD-39..AD-42, ST-14..ST-15, PA-16
│      Student → route + stop · Capacity · Requests · Approvals
│
├── 8. FINANCE ────────────────────────────────────── AD-85..AD-92, ST-16..ST-19, PA-17..PA-19   [NEW]
│      Fees · Passes · Payments · Dues · Concessions · Refunds
│
├── 9. TRIP LIFECYCLE ─────────────────────────────── AD-63..AD-68, DR-01..DR-11
│      Generation · Start · Run · Complete · Cancel · Reassign  ◄── THE SPINE
│
├── 10. TRACKING ──────────────────────────────────── AD-02, AD-07..AD-08, ST-02, PA-03
│      Position ingest · Geofence · ETA · Live map · Replay
│
├── 11. ATTENDANCE ────────────────────────────────── AD-69..AD-71, DR-06..DR-07, ST-09..ST-11, PA-07..PA-10
│      Manifest · Boarding · Alighting · Absence · Reconciliation
│
├── 12. SAFETY & INCIDENTS ────────────────────────── AD-72..AD-77, DR-12..DR-16
│      Incidents · SOS · Left-behind · Emergency response
│
├── 13. MAINTENANCE ───────────────────────────────── AD-78..AD-84, DR-17..DR-20
│      Tickets · Preventive · Parts · Vendors · Service history
│
├── 14. OPTIMISATION ──────────────────────────────── AD-75..AD-76, AD-24
│      Replacement · Consolidation · Capacity planning
│
├── 15. COMMUNICATION ─────────────────────────────── AD-93..AD-96, SH-14..SH-16, all *-notifications
│      Notifications · Announcements · Templates · Broadcast · Enquiries
│
├── 16. REPORTING ─────────────────────────────────── AD-97..AD-103
│      Operational · Fleet · Occupancy · Incident · Financial · Custom
│
└── 17. GOVERNANCE ────────────────────────────────── AD-108..AD-110, SH-18
       Audit · Retention · Data subject rights · System health
```

---

## 2. Navigation hierarchy — Admin console

```
/  Dashboard                                                        AD-01
│
├── Live Operations
│   ├── Live Map ......................................... AD-02
│   │   └── (panel) Bus → Trip Monitor ................... AD-07
│   ├── Active Trips ..................................... AD-03
│   ├── Today's Schedule ................................. AD-04
│   ├── Route Live View .................................. AD-08
│   ├── Alerts ........................................... AD-05
│   │   └── Alert Detail ................................. AD-06
│   ├── Status Board ..................................... AD-09
│   ├── Broadcast ........................................ AD-10
│   └── Handover Log ..................................... AD-11
│
├── Fleet
│   ├── Buses ............................................ AD-12
│   │   ├── Bus Detail ................................... AD-13
│   │   │   ├── Edit ..................................... AD-15
│   │   │   ├── Status Change ............................ AD-16
│   │   │   ├── Documents ................................ AD-17
│   │   │   ├── Occupancy History ........................ AD-20
│   │   │   ├── Inspections .............................. AD-22
│   │   │   └── Retire / Restore ......................... AD-23
│   │   ├── Add Bus ...................................... AD-14
│   │   └── Import ....................................... AD-18
│   ├── Assignment Board ................................. AD-21
│   ├── Fuel Log ......................................... AD-19
│   ├── Capacity Planner ................................. AD-24
│   └── Maintenance → (see Maintenance)
│
├── People
│   ├── Students ......................................... AD-35
│   │   ├── Student Detail ............................... AD-36
│   │   │   └── Assign Transport ......................... AD-39
│   │   ├── Add / Edit ................................... AD-37 / AD-38
│   │   ├── Bulk Assignment .............................. AD-40
│   │   ├── Import ....................................... AD-41
│   │   └── Requests Queue ............................... AD-42
│   ├── Drivers .......................................... AD-25
│   │   ├── Driver Detail ................................ AD-26
│   │   ├── Add / Edit ................................... AD-27 / AD-28
│   │   ├── Roster ....................................... AD-29
│   │   ├── Leave ........................................ AD-30
│   │   ├── Documents .................................... AD-31
│   │   ├── Performance .................................. AD-32
│   │   ├── Import ....................................... AD-33
│   │   └── Compliance Board ............................. AD-34
│   ├── Parents .......................................... AD-43
│   │   ├── Parent Detail ................................ AD-44
│   │   └── Link Verification ............................ AD-45
│   └── Staff ............................................ AD-46
│       ├── Staff Detail ................................. AD-47
│       └── Invite ....................................... AD-48
│
├── Network
│   ├── Routes ........................................... AD-49
│   │   ├── Route Detail ................................. AD-50
│   │   │   ├── Stop Manager ............................. AD-53
│   │   │   │   └── Stop Detail .......................... AD-54
│   │   │   └── Edit ..................................... AD-52
│   │   └── Add Route .................................... AD-51
│   ├── Stop Library ..................................... AD-55
│   ├── Schedules ........................................ AD-56
│   │   ├── Schedule Detail .............................. AD-57
│   │   └── Add / Edit ................................... AD-58 / AD-59
│   ├── Timetable Grid ................................... AD-60
│   ├── Service Calendar ................................. AD-61
│   └── Variants ......................................... AD-62
│
├── Operations
│   ├── Trips ............................................ AD-63
│   │   ├── Trip Detail .................................. AD-64
│   │   ├── Create Ad-hoc ................................ AD-65
│   │   ├── Cancel ....................................... AD-67
│   │   └── Reassign ..................................... AD-68
│   ├── Generation Review ................................ AD-66
│   ├── Attendance ....................................... AD-69
│   │   ├── Student History .............................. AD-70
│   │   └── Daily Summary ................................ AD-71
│   ├── Incidents ........................................ AD-72
│   │   └── Incident Detail .............................. AD-73
│   ├── SOS Alerts ....................................... AD-74
│   ├── Replacements ..................................... AD-75
│   ├── Consolidation .................................... AD-76
│   └── Left Behind ...................................... AD-77
│
├── Maintenance
│   ├── Queue ............................................ AD-78
│   │   └── Ticket Detail ................................ AD-79
│   ├── Create Ticket .................................... AD-80
│   ├── Preventive Schedule .............................. AD-81
│   ├── Service History .................................. AD-82
│   ├── Parts ............................................ AD-83
│   └── Workshops ........................................ AD-84
│
├── Finance                                                        [NEW]
│   ├── Fee Structures ................................... AD-85
│   ├── Passes ........................................... AD-86
│   ├── Payments ......................................... AD-87
│   ├── Outstanding Dues ................................. AD-88
│   ├── Concessions ...................................... AD-89
│   ├── Revenue Report ................................... AD-90
│   ├── Refunds .......................................... AD-91
│   └── Settings ......................................... AD-92
│
├── Communication
│   ├── Announcements .................................... AD-93
│   ├── Notification Log ................................. AD-94
│   ├── Templates ........................................ AD-95
│   └── Policy ........................................... AD-96
│
├── Reports
│   ├── Library .......................................... AD-97
│   ├── Operational / Fleet / Occupancy / Incident ....... AD-98..AD-101
│   ├── Custom Builder ................................... AD-102
│   └── Scheduled ........................................ AD-103
│
└── Administration
    ├── Users ............................................ AD-104
    ├── Roles & Permissions .............................. AD-105
    ├── Settings ......................................... AD-106
    ├── Integrations ..................................... AD-107
    ├── Audit Log ........................................ AD-108
    ├── Data Management .................................. AD-109
    ├── System Health .................................... AD-110
    └── Impersonation .................................... AD-111
```

---

## 3. Navigation — mobile clients

### Driver app

```
[Persistent: SOS control on every screen]

Tab-less. Duty-centric.

Home (Today) ......................... DR-01
├── Trip Detail ...................... DR-02 ──► Pre-Trip Inspection ... DR-03
│                                                └──► Start Confirm .... DR-04
│                                                      └──► ACTIVE TRIP  DR-05  ◄── takes over the app
│                                                            ├── Stop Detail ..... DR-06
│                                                            ├── Manifest ........ DR-07
│                                                            ├── Report Incident . DR-13
│                                                            └── End Trip ........ DR-08
│                                                                  └── Summary ... DR-09
├── Trip History ..................... DR-10
├── Route Preview .................... DR-11
├── My Bus ........................... DR-17 ──► Defect Report ......... DR-20
│                                       └──► Checklist History ......... DR-18
│                                       └──► Fuel Entry ................ DR-19
├── My Schedule ...................... DR-21 ──► Leave Request ......... DR-22
├── My Duty Hours .................... DR-23
├── My Incidents ..................... DR-14 ──► Incident Detail ....... DR-15
├── Notifications .................... DR-26
├── Emergency Contacts ............... DR-16   (offline, no session required)
└── Profile .......................... DR-24 ──► Performance ........... DR-25
                                        └──► Settings .................. DR-27
```

### Student app

```
[Bottom tabs: Home · Track · Schedule · More]

Home ................................. ST-01
├── Live Tracking .................... ST-02 ──► Trip Detail ........... ST-03
│                                       └──► My Stop .................. ST-04
├── Route Map ........................ ST-05
├── Nearby Stops ..................... ST-06
└── Arrival Reminder ................. ST-07

Schedule ............................. ST-08
├── Mark Absence ..................... ST-09
├── Service Calendar ................. ST-13
└── Attendance ....................... ST-10 ──► Detail ............... ST-11
                                        └──► Trip History ............. ST-12

More
├── My Transport ..................... ST-14 ──► Request Change ....... ST-15
├── My Pass .......................... ST-16 ──► Renew ................ ST-17
├── Payments ......................... ST-18 ──► Fee Details .......... ST-19
├── My Guardians ..................... ST-20
├── Notifications .................... ST-21
├── Announcements .................... ST-22
├── Report a Problem ................. ST-23 ──► My Reports ........... ST-24
├── Trip Feedback .................... ST-25
├── Lost & Found ..................... ST-26
├── Profile .......................... ST-27
├── Emergency Contacts ............... ST-28
└── Settings ......................... ST-29
```

### Parent app `[NEW]`

```
[Bottom tabs: Home · Track · Attendance · More]

Home ................................. PA-01 ──► Child Selector ....... PA-02
├── Live Child Tracking .............. PA-03 ──► Journey Detail ....... PA-04
├── Child Detail ..................... PA-05 ──► Child Schedule ....... PA-06
Attendance ........................... PA-07
├── Mark Absent ...................... PA-08
├── Absence History .................. PA-09
└── Attendance Alerts ................ PA-10
More
├── Notifications .................... PA-11
├── Announcements .................... PA-12
├── Contact Office ................... PA-13 ──► My Enquiries ......... PA-14
├── Report a Concern ................. PA-15
├── Request Route Change ............. PA-16
├── Child's Pass ..................... PA-17
├── Payments ......................... PA-18 ──► Fee Details .......... PA-19
├── Request Child Link ............... PA-20
├── Profile .......................... PA-21
└── Settings ......................... PA-22
```

---

## 4. Screen transition map — critical paths

```
TRIP EXECUTION (driver)
DR-01 ──start──► DR-03 ──pass──► DR-04 ──► DR-05 ──end──► DR-08 ──► DR-09 ──► DR-01
                   │ fail                    │
                   ▼                         ├──incident──► DR-13 ──► DR-05
                 AD-80 (ticket)              ├──SOS───────► DR-12 ──► AD-74
                 AD-05 (alert)               └──stop──────► DR-06 ──► DR-05

INCIDENT → REPLACEMENT (cross-role)
DR-13 ──► AD-72 ──► AD-73 ──┬──► AD-78 ──► AD-79 ──certify──► AD-13 (bus AVAILABLE)
                            └──► AD-75 ──approve──► dispatch ──► N-14 ──► ST-02 / PA-03

STUDENT ASSIGNMENT
AD-35 ──► AD-36 ──► AD-39 ──► N-25 ──► ST-14 / PA-05
   ▲                  │
   └──── AD-42 ◄──────┴──── ST-15 / PA-16  (request path)

GUARDIAN LINKING
PA-20 ──► N-29 ──► ST-20 (student approves) ──┐
                    AD-45 (staff approves) ───┼──► link granted ──► N-30 ──► PA-01
                                              │
                    rejected ─────────────────┴──► N-28

DAY SETUP
AD-61 ──► BG-01 ──► AD-66 ──review──► AD-01 ──► AD-02 (live)
                       │
                       └──exception──► AD-68 (reassign) / AD-67 (cancel) / AD-29 (roster)
```

---

## 5. Module dependency graph

```
                    ┌──────────────────┐
                    │ 1. IDENTITY      │
                    └────────┬─────────┘
                             │ (all)
        ┌──────────┬─────────┼──────────┬──────────┐
        ▼          ▼         ▼          ▼          ▼
   ┌────────┐ ┌────────┐ ┌───────┐ ┌────────┐ ┌────────┐
   │3. FLEET│ │4. WORK-│ │5.PEOPLE│ │6.NET-  │ │2.CONFIG│
   │        │ │  FORCE │ │        │ │  WORK  │ │        │
   └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘
       │          │          │          │          │
       │          │          ▼          ▼          │
       │          │      ┌───────────────────┐     │
       │          │      │ 7. ASSIGNMENT     │◄────┤
       │          │      └─────────┬─────────┘     │
       │          │                │               │
       │          │      ┌─────────▼─────────┐     │
       │          │      │ 8. FINANCE  [NEW] │     │
       │          │      └─────────┬─────────┘     │
       │          │                │               │
       └──────────┴────────┬───────┴───────────────┘
                           ▼
                ┌─────────────────────┐
                │ 9. TRIP LIFECYCLE   │  ◄── critical path
                └──────────┬──────────┘
       ┌───────────┬───────┼───────┬─────────────┐
       ▼           ▼       ▼       ▼             ▼
  ┌────────┐ ┌─────────┐ ┌────┐ ┌────────┐ ┌──────────┐
  │10.TRACK│ │11.ATTEND│ │ETA │ │12.SAFETY│ │14.OPTIMISE│
  └────┬───┘ └────┬────┘ └─┬──┘ └───┬────┘ └────┬─────┘
       │          │        │        │           │
       │          │        │        ▼           │
       │          │        │  ┌───────────┐     │
       │          │        │  │13.MAINTEN.│     │
       │          │        │  └─────┬─────┘     │
       │          │        │        │           │
       └──────────┴────────┴────────┴───────────┘
                           │
                  ┌────────▼─────────┐
                  │15. COMMUNICATION │  ◄── subscriber; never a blocker
                  └────────┬─────────┘
                           ▼
                  ┌──────────────────┐
                  │  16. REPORTING   │  ◄── reads all, writes none
                  └──────────────────┘

                  ┌──────────────────┐
                  │  17. GOVERNANCE  │  ◄── written by ALL, modified by none
                  └──────────────────┘
```

**Rules encoded in the graph**

- Nothing precedes Identity
- Modules 3–6 are parallel-buildable and have no interdependency
- Module 9 is the critical path: 10–14 cannot be built or meaningfully tested before it exists
- Module 15 is a subscriber. Its failure must degrade, never block, the publisher
- Module 16 has no write path anywhere
- Module 17 is an obligation of every module, not a phase of its own

---

## 6. Shared components

| Component | Used by | Behaviour |
|---|---|---|
| **Entity picker** | Every assignment screen | Searchable, filtered to *eligible* candidates only, showing why an ineligible one is excluded rather than hiding it |
| **Map view** | AD-02, AD-53, ST-02, PA-03, DR-05 | Route/stop/geofence overlays, marker clustering, staleness indication, provider-failure fallback |
| **Status pill** | Everywhere | Colour **plus** text or icon; never colour alone |
| **Data table** | Every list | Sort, filter chips, column control, bulk select, export, URL-persisted state |
| **Filter bar** | Every list | Removable chips, result count, clear-all, session persistence |
| **Confirmation dialog** | Every destructive action | Typed confirmation, effect preview, recipient count where notifying |
| **Audit trail panel** | Every detail screen | Who, what, when, before/after — read-only |
| **Notification bell** | All clients | Unread badge, live updates, deep links |
| **Empty state** | Every list | What / why / next action |
| **Offline banner** | Mobile | Connection state, queue depth, manual sync |
| **SOS control** | Driver app, every screen | Press-and-hold, works offline |
| **Date-range picker** | Reports, history | Presets plus custom, timezone-labelled |
| **Photo capture** | Incidents, inspections, fuel | Compression, offline queue, EXIF location capture |
| **Capacity indicator** | Assignment, trips, boarding | Used/total with a threshold colour and a hard-limit state |
| **Permission-aware action** | Everywhere | Hidden when the role can never do it; disabled **with a reason** when the state forbids it now |

---

## 7. Shared workflows

| Workflow | Invoked from | Definition |
|---|---|---|
| Approval | Requests, replacements, consolidation, leave, links | Submit → review → decide with reason → notify requester → audit |
| Assignment | Transport, bus→driver, driver→trip | Eligibility filter → conflict check → capacity check → commit under lock → notify → audit |
| State transition | Bus, driver, trip, ticket, pass | Validate against the machine → check guards → commit → emit event → notify → audit |
| Bulk operation | Lists across all modules | Select → preview → validate per item → commit → per-item report → retry failed |
| Import | Buses, drivers, students | Upload → map → validate all → preview → match/merge → commit → report |
| Export | Every list and report | Filter → permission-filter fields → generate (async above threshold) → deliver → audit |
| Notification dispatch | Every module | Resolve recipients → apply entitlement → apply preferences unless critical → dispatch → record outcome → retry/escalate |
| Search | Global and per-screen | Literal-text match → permission-scoped results → grouped by entity |
| Audit write | Every mutation | Actor, action, entity, before, after, address, correlation id — never modifiable |

---

## 8. Coverage check

| Phase | Requirement | Where |
|---|---|---|
| 1 | System overview | [01 §1](01-system-analysis.md) |
| 1 | Roles and responsibilities | [01 §2](01-system-analysis.md) |
| 1 | Business and system rules | [01 §3](01-system-analysis.md) |
| 1 | Edge cases and exceptions | [01 §4](01-system-analysis.md) |
| 1 | Module dependencies | [01 §5](01-system-analysis.md), [10 §5](#5-module-dependency-graph) |
| 1 | Navigation flow | [01 §6](01-system-analysis.md), [10 §2–4](#2-navigation-hierarchy--admin-console) |
| 1 | User interactions | [01 §7](01-system-analysis.md) |
| 1 | End-to-end operational flow | [01 §8](01-system-analysis.md) |
| 2 | Every screen, all attributes | [02](02-screens-conventions.md)–[07](07-screens-parent.md) — 207 screens |
| 3 | Actions, dialogs, validations | [08 §1, §6](08-functionality.md) |
| 3 | Background processes | [08 §3](08-functionality.md) — 23 processes |
| 3 | Status changes | [08 §2](08-functionality.md) — 8 state machines |
| 3 | Notification triggers | [08 §4](08-functionality.md) — 44 triggers |
| 3 | Permission checks | [08 §5](08-functionality.md) |
| 4 | All required flows + alternates + failures | [09](09-system-flows.md) — 16 flows |
| 5 | Complete application map | This document |

---

## 9. Open decisions for the product owner

The blueprint is complete as a design. These are **scope and policy choices**, not gaps in
the analysis — each needs a decision before build, and each is a decision only the institution
can make.

1. **Parent role** — in or out of the first release? It is the largest single addition here
   (22 screens plus the linking flow) and the largest driver of perceived value.
2. **Finance module** — build it, or integrate with the college's existing fee system? If
   integrating, the pass entitlement check still has to live here.
3. **Named boarding vs. headcount** — does the driver count heads (`+1`/`−1`) or identify
   individual students? Named boarding is what makes "your child boarded" possible, and it
   requires either a card, a QR code, or a manual tap per student. This single decision
   determines whether the parent app's core promise is deliverable.
4. **Duty-hour regulation** — which regulatory regime applies, and what are the ceilings?
5. **Break-glass policy** — who may invoke it, for how long, and who reviews it after?
6. **Retention periods** — per data class, especially minors' location traces.
7. **Left-behind escalation** — what is the guaranteed response time, and who owns it?
8. **Offline trust window** — how long may a driver operate fully offline before operations
   must intervene by voice?
