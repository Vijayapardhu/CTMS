# Wireframes

Structure only. No colour, no final copy. Every region maps to a component from
`06-component-library.md`.

Drawn at the primary target, **1440 × 900**.

## Shell

```text
┌────────────┬──────────────────────────────────────────────────────────────┐
│            │  Live Operations                    [🔔 3]  Ravi Kumar  ▾    │ C3  56
│  CTMS      ├──────────────────────────────────────────────────────────────┤
│            │                                                              │
│ ▸ Dashboard│   content region, max 1600, gutter 24                        │
│            │                                                              │
│ OPERATIONS │                                                              │
│  Live      │                                                    ┌─────────┤
│  Trips     │                                                    │ C16     │
│  Routes    │                                                    │ drawer  │
│            │                                                    │ 480     │
│ FLEET      │                                                    │         │
│  Buses     │                                                    │         │
│  Drivers   │                                                    │         │
│  Inspect.  │                                                    │         │
│  Maint.    │                                                    │         │
│            │                                                    │         │
│ SAFETY     │                                                    │         │
│  Incidents │                                                    │         │
│            │                                                    │         │
│ PEOPLE     │                                                    │         │
│  Students  │                                                    │         │
│            │                                                    │         │
│ Reports    │                                                    │         │
│ Admin ⚠    │                                                    │         │
└────────────┴────────────────────────────────────────────────────┴─────────┘
   C2 240                                                       toast ↘ C24
```

`Admin ⚠` is present only for `SUPER_ADMIN`. Below that level the section does
not render.

## A1 Dashboard

```text
  Good morning, Ravi                                    Thursday 12 March
  ┌──────────┬──────────┬──────────┬──────────┬──────────┐
  │ 18       │ 4        │ 12       │ 3        │ 2        │   C5 ×5
  │ Trips    │ Running  │ Buses    │ Open     │ In       │
  │ today    │ now      │ available│ incidents│ workshop │
  └──────────┴──────────┴──────────┴──────────┴──────────┘

  ┌─────────────────────────────────────┬──────────────────────────────┐
  │  ATTENTION REQUIRED                 │  LIVE                        │
  │  ● SOS — AP-39-X-1122 — 2 min       │  ┌────────────────────────┐  │
  │  ● Breakdown — AP-39-X-0987 — 18m   │  │                        │  │
  │  ▲ Inspection failed — AP-39-X-3311 │  │   C12 map, 4 markers   │  │
  │  ▲ Maintenance due — AP-39-X-0450   │  │                        │  │
  │  ◆ Insurance expires in 6 days      │  └────────────────────────┘  │
  │                                     │  4 buses running             │
  │  [ Open incidents → ]               │  [ Live Operations → ]       │
  └─────────────────────────────────────┴──────────────────────────────┘

  TODAY'S OPERATIONS                                      [ All trips → ]
  ┌──────┬───────────────────┬──────────┬────────┬──────────┬──────────┐
  │ Trip │ Route             │ Bus      │ Driver │ Status   │ ETA      │  C8
  ├──────┼───────────────────┼──────────┼────────┼──────────┼──────────┤
  │ …    │ Velangi → Aditya  │ AP-39-…  │ Ravi   │ Running  │ 12 min   │
  │ …    │ Hostel → Campus   │ AP-39-…  │ Kumar  │ Scheduled│ 08:15    │
  │ …    │ City → Campus     │ AP-39-…  │ Suresh │ Completed│ —        │
  └──────┴───────────────────┴──────────┴────────┴──────────┴──────────┘
```

Attention Required is ordered by consequence, not time: SOS, then breakdown,
then tracking lost, then failed inspection, then maintenance due, then expiring
documents. Empty is a sentence, not a blank panel: "Nothing needs attention."

## A2 Live Operations

```text
  Live Operations          Tracking 12 of 14 ⓘ            ⟳ 30s   [Fit all]
  ┌──────────────────────────────────────────────┬───────────────────────┐
  │                                              │  ACTIVE TRIPS         │
  │                                              │ ┌───────────────────┐ │
  │            C12  map, 60%                     │ │● AP-39-X-1122     │ │
  │                                              │ │  Velangi→Aditya   │ │
  │      ◉ selected     ○ running                │ │  Ravi · 12 min    │ │
  │      ◌ stale        ⊗ incident               │ ├───────────────────┤ │
  │                                              │ │○ AP-39-X-0987 …   │ │
  │                                              │ └───────────────────┘ │
  └──────────────────────────────────────────────┴───────────────────────┘
                                    selecting a bus opens C16 ───────────┘
```

Drawer D1, on selection: bus · driver · route · current stop · next stop ·
**road distance** · ETA · GPS age · occupancy · [Open trip →].

"Tracking 12 of 14" is always visible when the cap bites (G2-1).

## A3 Trips

```text
  Trips                                                    [ Generate day ▾ ]
  ┌────────────────────────────────────────────────────────────────────────┐
  │ [Today ▾] [Status ▾] [Route ▾] [Driver ▾] [Bus ▾]      2 active  Clear │ C9
  └────────────────────────────────────────────────────────────────────────┘
  ┌──────┬─────────┬──────────┬─────────┬────────┬─────────┬──────┬─────┬──┐
  │ Trip │ Route   │ Date     │ Depart  │ Bus    │ Driver  │Status│Pax  │⋮ │ C8
  ├──────┼─────────┼──────────┼─────────┼────────┼─────────┼──────┼─────┼──┤
  │ …    │ …       │ 12 Mar   │ 07:30   │ AP-…   │ Ravi    │ ●Run │20/60│⋮ │
  └──────┴─────────┴──────────┴─────────┴────────┴─────────┴──────┴─────┴──┘
                                                    1–20 of 142    ‹ 1 2 3 › C23
```

Columns dropped below 1280: Date, Pax. Never scrolls horizontally.

## A4 Trip Details

```text
  ‹ Trips        Velangi → Aditya University      ● RUNNING    [Cancel][⋮]
  ┌──────────────────────────────────┬─────────────────────────────────────┐
  │ OVERVIEW                         │  STOPS                     C17      │
  │ Bus       AP-39-X-1122           │  ✓ Velangi          07:30 depart    │
  │ Driver    Ravi Kumar             │  ✓ Peddapuram       07:52 arrived   │
  │ Route     R-04 · 8 stops         │  ◉ Kathipudi        ~08:10  37 km   │
  │ Departed  07:30 (6 min late)     │  ○ Aditya Univ.     08:45           │
  │ Occupancy 20 / 60                │                                     │
  ├──────────────────────────────────┤  MANIFEST · Kathipudi               │
  │ LIVE                             │  12 expected · 9 boarded            │
  │  [ small map, position + route ] │                                     │
  ├──────────────────────────────────┼─────────────────────────────────────┤
  │ INCIDENTS (1)                    │  CORRECTIONS (0)                    │
  └──────────────────────────────────┴─────────────────────────────────────┘
```

## A5 Fleet · A6 Bus Details

```text
  Buses                                                       [ + Add bus ]
  [Status ▾] [Search registration…]
  ┌────────────┬────────┬─────┬──────────┬────────┬────────┬─────────┬───┐
  │ Registration│ Model │ Cap │ Status   │ Driver │ Trip   │ Ready   │ ⋮ │
  ├────────────┼────────┼─────┼──────────┼────────┼────────┼─────────┼───┤
  │ AP-39-X-…  │ Tata   │ 60  │ ●Running │ Ravi   │ TR-001 │ ✓       │ ⋮ │
  │ AP-39-X-…  │ Ashok  │ 45  │ ▲Maint.  │ —      │ —      │ ✗ 2     │ ⋮ │
  └────────────┴────────┴─────┴──────────┴────────┴────────┴─────────┴───┘

  A6 (drawer or page):
  ┌ Overview ─ Readiness ─ Inspections ─ Maintenance ─ Documents ─ Incidents ┐
  │  Readiness:  ✗ Not cleared                                               │
  │    · No pre-trip inspection has been completed today.                    │
  │    · Insurance is missing or expired.                                    │
  └──────────────────────────────────────────────────────────────────────────┘
```

Readiness reasons are the server's sentences, rendered as a list, verbatim.

## A8 Incidents · A9 Incident Details

```text
  Incidents          [Open ▾] [Type ▾] [Bus ▾]            ⓘ open queue only
  ┌───┬──────────┬────────────┬────────┬────────┬───────┬────────────┬───┐
  │ ! │ Type     │ Bus        │ Driver │ Trip   │ Time  │ Status     │ ⋮ │
  ├───┼──────────┼────────────┼────────┼────────┼───────┼────────────┼───┤
  │ ● │ SOS      │ AP-39-X-…  │ Ravi   │ TR-001 │ 08:12 │ Reported   │ ⋮ │
  │ ▲ │ Breakdown│ AP-39-X-…  │ Kumar  │ TR-004 │ 07:55 │ Acknowledged│⋮ │
  └───┴──────────┴────────────┴────────┴────────┴───────┴────────────┴───┘

  A9:
  ┌──────────────────────────────────────────────────────────────────────┐
  │ ● SOS · CRITICAL · Reported 08:12          [Acknowledge] [Resolve]   │
  │ "Emergency (SOS)"                                                    │
  │ Bus AP-39-X-1122 · Ravi Kumar · TR-001 · 16.8697, 82.1142  [map]     │
  ├──────────────────────┬───────────────────────────────────────────────┤
  │ EVIDENCE       C25   │  TIMELINE                              C17    │
  │  [photo] [photo]     │  08:12 Reported by Ravi Kumar                 │
  │                      │  08:14 Acknowledged by Priya (Supervisor)     │
  ├──────────────────────┴───────────────────────────────────────────────┤
  │ REPLACEMENT · Recommended AP-39-X-2244 (4.2 km)  [Approve] [Reject]  │
  └──────────────────────────────────────────────────────────────────────┘
```

## A10 Maintenance · A11 Inspections

```text
  Maintenance   [Status ▾] [Priority ▾] [Bus ▾]              [ + Open ticket ]
  ┌────────┬──────────┬───────────────┬──────────┬───────────┬──────────┬──┐
  │ Ticket │ Bus      │ Issue         │ Priority │ Status    │ Assigned │⋮ │
  └────────┴──────────┴───────────────┴──────────┴───────────┴──────────┴──┘

  Inspections — today's failures                    ⓘ today only, see gaps
  ┌────────────┬───────────┬────────────────────────┬──────────┬──────────┐
  │ Bus        │ Driver    │ Failed items           │ Evidence │          │
  ├────────────┼───────────┼────────────────────────┼──────────┼──────────┤
  │ AP-39-X-…  │ Kumar     │ Brakes, Lights         │ 2 photos │ [Ticket] │
  └────────────┴───────────┴────────────────────────┴──────────┴──────────┘
```

## A15 Reports · A16 Audit

```text
  Reports   [Trips|Attendance|Fleet|Incidents|Maintenance|Occupancy]
  [ 1 Mar – 12 Mar ▾ ]                                 [ Download this table ]
  ┌──────────────┬──────────┬──────────┬──────────┐
  │ summary tiles                                  │
  └──────────────┴──────────┴──────────┴──────────┘
  ┌────────────────────────────────────────────────────────────────────────┐
  │ report table                                                     C8    │
  └────────────────────────────────────────────────────────────────────────┘

  Audit   [ Audit log | Data access log ]      [Action ▾] [ date range ▾ ]
  ┌───────────┬──────────┬──────────┬───────────────┬──────────┬──────────┐
  │ When      │ Actor    │ Action   │ Resource      │ Record   │ IP       │
  └───────────┴──────────┴──────────┴───────────────┴──────────┴──────────┘
```

The two logs are separate tabs, never merged rows. One is about change, one
about access.

## States, drawn

```text
LOADING             EMPTY                    ERROR                OFFLINE
┌──────────┐        ┌──────────┐             ┌──────────┐         ┌────────────┐
│ ▤▤▤▤▤▤   │        │    ⊙     │             │    ⚠     │         │ ⛅ Offline  │
│ ▤▤▤▤     │        │ No trips │             │ Request  │         │ — showing  │
│ ▤▤▤▤▤▤   │        │ today    │             │ failed   │         │ last known │
└──────────┘        └──────────┘             │ [Retry]  │         └────────────┘
skeleton at         calm, never red          └──────────┘         banner pushes
final height                                                      content down
```
