# Driver App — Phase 5: Wireframes

**Derived from:** [04 — State machines](04-state-machines.md)
**Fidelity:** structural only. No colour, no icon choices, no type scale. Those come out of Phases 6–7 once we know what the layouts actually need.

---

## The layout rule

The driver may be standing, cold, wearing gloves, in direct sun, with a bus full of children waiting. Every screen answers three questions in this order:

1. **What is happening?** — top third, large, glanceable
2. **What do I do?** — bottom third, thumb-reachable, one primary action
3. **What else exists?** — the middle, scrollable, optional

The middle is the only part that scrolls. The top and bottom are fixed, because a driver should never have to scroll to find the button that starts the trip.

---

## R1 · Trip — `none`

```
┌────────────────────────────────────┐
│  Good morning, Ravi        [ ⚙ ]   │  ← identity, settings
├────────────────────────────────────┤
│                                    │
│                                    │
│         ┌──────────────┐           │
│         │   (empty)    │           │
│         └──────────────┘           │
│                                    │
│      No trip assigned today        │  ← large, calm, not an error
│                                    │
│   If you expect one, contact the   │
│         transport office.          │
│                                    │
│      ┌──────────────────────┐      │
│      │   Call the office    │      │  ← the only action
│      └──────────────────────┘      │
│                                    │
├────────────────────────────────────┤
│   Trip    Map    Alerts     Me     │
└────────────────────────────────────┘
```

An empty day is not a failure. No red, no warning icon.

---

## R1 · Trip — `blocked`

```
┌────────────────────────────────────┐
│  Route 7 · Morning        07:15    │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │  KA-05-MJ-3391               │  │  ← the bus, unmissable
│  │  NOT READY                   │  │
│  └──────────────────────────────┘  │
│                                    │
│  You can fix this                  │  ← actionable group FIRST
│  ┌──────────────────────────────┐  │
│  │ ○ No pre-trip inspection has │  │
│  │   been completed today.      │  │
│  └──────────────────────────────┘  │
│                                    │
│  Operations must fix this          │  ← visually quieter
│  ┌──────────────────────────────┐  │
│  │ ○ Insurance expired 2 days   │  │
│  │   ago.                       │  │
│  └──────────────────────────────┘  │
│                                    │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │     Start inspection         │  │  ← only if something is actionable
│  └──────────────────────────────┘  │
│  ┌──────────────────────────────┐  │
│  │     Call the office          │  │
│  └──────────────────────────────┘  │
├────────────────────────────────────┤
│   Trip    Map    Alerts     Me     │
└────────────────────────────────────┘
```

**The two-group split is the whole point.** Without it a driver taps "Start inspection", passes it, and is still blocked by the insurance — with no idea why.

---

## R1 · Trip — `ready`

```
┌────────────────────────────────────┐
│  Route 7 · Morning        07:42    │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │  KA-05-MJ-3391               │  │
│  │  READY                       │  │
│  │  Inspection passed 07:38     │  │
│  └──────────────────────────────┘  │
│                                    │
│  Departs 08:00 · 14 stops          │
│  32 students expected              │
│                                    │
│  First stop                        │
│  ┌──────────────────────────────┐  │
│  │ Anand Nagar · 08:06          │  │
│  └──────────────────────────────┘  │
│                                    │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │                              │  │
│  │        START TRIP            │  │  ← full width, tall (64dp)
│  │                              │  │
│  └──────────────────────────────┘  │
├────────────────────────────────────┤
│   Trip    Map    Alerts     Me     │
└────────────────────────────────────┘
```

---

## R1 · Trip — `running`

The screen a driver sees most. Everything is glanceable; nothing needs reading.

```
┌────────────────────────────────────┐
│  Route 7        ● LIVE      08:23  │  ← GPS pill, top-right
├────────────────────────────────────┤
│                                    │
│         23 / 40                    │  ← enormous. The one number
│        on board                    │     that matters
│                                    │
├────────────────────────────────────┤
│  NEXT STOP                         │
│  ┌──────────────────────────────┐  │
│  │  Kalyan Nagar                │  │
│  │  4 min · 1.2 km · 6 waiting  │  │
│  └──────────────────────────────┘  │
│                                    │
│  ┌─────────────┐  ┌─────────────┐  │
│  │   BOARD     │  │   ALIGHT    │  │  ← 2 huge targets, one-handed
│  │     +       │  │      −      │  │
│  └─────────────┘  └─────────────┘  │
│                                    │
│  ┌──────────────────────────────┐  │
│  │  Report a problem            │  │
│  └──────────────────────────────┘  │
├────────────────────────────────────┤
│  [ ▲ SOS — hold ]                  │  ← persistent chrome, above tabs
├────────────────────────────────────┤
│   Trip    Map    Alerts     Me     │
└────────────────────────────────────┘
```

**Running trip — degraded (GPS lost, 12 queued)**

```
┌────────────────────────────────────┐
│  Route 7      ○ NO SIGNAL   08:31  │
├────────────────────────────────────┤
│ ⚠ Offline · 12 changes waiting     │  ← banner, persistent, not a toast
├────────────────────────────────────┤
│                                    │
│         26 / 40                    │
│        on board  · 3 pending       │  ← pending shown, never hidden
│                                    │
├────────────────────────────────────┤
│  NEXT STOP                         │
│  ┌──────────────────────────────┐  │
│  │  Kalyan Nagar                │  │
│  │  ~4 min (estimated)          │  │  ← degraded ETA labelled
│  │  Position 6 min old          │  │  ← is_stale, explicit
│  └──────────────────────────────┘  │
│                                    │
│  ┌─────────────┐  ┌─────────────┐  │
│  │   BOARD +   │  │  ALIGHT −   │  │  ← still fully functional
│  └─────────────┘  └─────────────┘  │
└────────────────────────────────────┘
```

Boarding still works. That is the test of the offline design.

---

## P9 · Inspection checklist

```
┌────────────────────────────────────┐
│  ←  Pre-trip inspection      3/14  │  ← progress in the title
├────────────────────────────────────┤
│  Odometer                          │
│  ┌──────────────────────────────┐  │
│  │  45 120                   km │  │
│  └──────────────────────────────┘  │
│  Must be at least 45 108 km        │  ← the constraint, before the error
├────────────────────────────────────┤
│                                    │
│  ┌──────────────────────────────┐  │
│  │  Brakes            ⚠ safety  │  │
│  │                              │  │
│  │  ┌──────────┐  ┌──────────┐  │  │
│  │  │   PASS   │  │   FAIL   │  │  │  ← 2 targets, ≥56dp tall
│  │  └──────────┘  └──────────┘  │  │
│  └──────────────────────────────┘  │
│                                    │
│  ┌──────────────────────────────┐  │
│  │  Lights                      │  │
│  │  ┌──────────┐  ┌──────────┐  │  │
│  │  │   PASS   │  │   FAIL   │  │  │
│  │  └──────────┘  └──────────┘  │  │
│  └──────────────────────────────┘  │
│                                    │
│              ⋮ (scrolls)           │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │   Review (11 remaining)      │  │  ← disabled until complete
│  └──────────────────────────────┘  │
└────────────────────────────────────┘
```

**Failed safety-critical item, expanded**

```
│  ┌──────────────────────────────┐  │
│  │  Brakes            ⚠ safety  │  │
│  │  ┌──────────┐  ┌══════════┐  │  │
│  │  │   PASS   │  ║   FAIL   ║  │  │  ← selected
│  │  └──────────┘  └══════════┘  │  │
│  │                              │  │
│  │  What did you find?          │  │
│  │  ┌────────────────────────┐  │  │
│  │  │ Pedal travel excessive │  │  │
│  │  └────────────────────────┘  │  │
│  │                              │  │
│  │  Photograph required         │  │
│  │  ┌────────────────────────┐  │  │
│  │  │  📷  Take photograph   │  │  │
│  │  └────────────────────────┘  │  │
│  └──────────────────────────────┘  │
```

---

## P10 · Inspection review — with a critical failure

```
┌────────────────────────────────────┐
│  ←  Review                         │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │  ⚠  This will take the bus   │  │  ← the consequence, BEFORE
│  │     out of service.          │  │     submitting
│  │                              │  │
│  │  A maintenance ticket will   │  │
│  │  be opened. You will not be  │  │
│  │  able to start this trip.    │  │
│  └──────────────────────────────┘  │
│                                    │
│  Failed (1)                        │
│  · Brakes — pedal travel excessive │
│    📷 attached                     │
│                                    │
│  Passed (13)                       │
│  · Lights, Tyres, Mirrors, …       │
│                                    │
│  Odometer 45 120 km                │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │      Submit inspection       │  │
│  └──────────────────────────────┘  │
│  ┌──────────────────────────────┐  │
│  │      Back to checklist       │  │
│  └──────────────────────────────┘  │
└────────────────────────────────────┘
```

---

## P13 · Stop details

```
┌────────────────────────────────────┐
│  ←  Kalyan Nagar          Stop 4/14│
├────────────────────────────────────┤
│  ARRIVED 08:27                     │
│                                    │
│  6 expected here                   │
│  ┌──────────────────────────────┐  │
│  │  ○ Priya S.        4B        │  │
│  │  ○ Arjun M.        4A        │  │
│  │  ● Kavya R.        5C   ✓    │  │  ← boarded
│  │  ○ …                         │  │
│  └──────────────────────────────┘  │
│                                    │
│  ┌─────────────┐  ┌─────────────┐  │
│  │  BOARD  +   │  │  ALIGHT −   │  │
│  └─────────────┘  └─────────────┘  │
│                                    │
│  ┌──────────────────────────────┐  │
│  │  Mark students left behind   │  │
│  └──────────────────────────────┘  │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │       Depart this stop       │  │
│  └──────────────────────────────┘  │
└────────────────────────────────────┘
```

**Not yet arrived** replaces the header with the manual fallback:

```
│  ┌──────────────────────────────┐  │
│  │       I'm at this stop       │  │  ← geofence missed
│  └──────────────────────────────┘  │
│  ┌──────────────────────────────┐  │
│  │       Skip this stop         │  │
│  └──────────────────────────────┘  │
```

---

## S1 · SOS confirm sheet

```
┌────────────────────────────────────┐
│                                    │
│              ▲                     │
│                                    │
│        Send emergency alert?       │
│                                    │
│   Operations will be alerted       │
│   immediately with your location.  │
│                                    │
│  ┌──────────────────────────────┐  │
│  │                              │  │
│  │       SEND ALERT             │  │  ← tall, unmissable
│  │                              │  │
│  └──────────────────────────────┘  │
│                                    │
│           Cancel                   │  ← text only, no weight
│                                    │
└────────────────────────────────────┘
```

No description field. No photo. No type picker. **One decision.**

---

## P17 · SOS active — queued offline

```
┌────────────────────────────────────┐
│              ▲                     │
│                                    │
│      ALERT SAVED                   │  ← never "failed"
│                                    │
│  You have no signal. This alert    │
│  will send automatically.          │
│  Retrying… (attempt 3)             │
│                                    │
│  Reach someone now:                │
│  ┌──────────────────────────────┐  │
│  │  📞  Call transport office   │  │
│  └──────────────────────────────┘  │
│  ┌──────────────────────────────┐  │
│  │  ✉  Send SMS with location   │  │
│  └──────────────────────────────┘  │
│                                    │
│  ────────────────────────────────  │
│           False alarm?             │
│         Withdraw this alert        │
└────────────────────────────────────┘
```

---

## P18 · Incident report — operational

```
┌────────────────────────────────────┐
│  ←  Report a problem               │
├────────────────────────────────────┤
│  What happened?                    │
│  ┌──────────────────────────────┐  │
│  │  Breakdown                ▾  │  │
│  └──────────────────────────────┘  │
│                                    │
│  Describe it                       │
│  ┌──────────────────────────────┐  │
│  │  Engine has cut out and will │  │
│  │  not restart.                │  │
│  └──────────────────────────────┘  │
│                                    │
│  Photograph  (required)            │
│  ┌──────────────────────────────┐  │
│  │  📷  Take photograph         │  │
│  └──────────────────────────────┘  │
│                                    │
│  ┌──────────────────────────────┐  │
│  │  Can the bus continue?       │  │  ← highest-consequence control
│  │                              │  │
│  │  ┌──────────┐ ┌───────────┐  │  │
│  │  │   YES    │ │    NO     │  │  │  ← equal weight, no default
│  │  └──────────┘ └───────────┘  │  │
│  │                              │  │
│  │  Choosing No takes the bus   │  │
│  │  out of service and starts   │  │
│  │  a replacement search.       │  │
│  └──────────────────────────────┘  │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │        Submit report         │  │
│  └──────────────────────────────┘  │
└────────────────────────────────────┘
```

---

## R2 · Map

```
┌────────────────────────────────────┐
│                                    │
│                                    │
│         ╭───╮                      │
│         │ ▲ │  ← bus, heading      │
│         ╰───╯                      │
│      ●━━━━━●━━━━━○━━━━━○           │  ← route: done / next / pending
│                                    │
│                          ┌───┐     │
│                          │ ⊕ │     │  ← recentre
│                          └───┘     │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │  Kalyan Nagar · 4 min        │  │  ← next stop, draggable sheet
│  │  ▁▁▁▁                        │  │
│  └──────────────────────────────┘  │
├────────────────────────────────────┤
│  [ ▲ SOS — hold ]                  │
├────────────────────────────────────┤
│   Trip    Map    Alerts     Me     │
└────────────────────────────────────┘
```

**Stale position** dims the marker and adds a badge — never a fresh-looking marker over old data:

```
│         ╭┄┄┄╮                      │
│         ┊ ▲ ┊  Position 6 min old  │
│         ╰┄┄┄╯                      │
```

---

## M3 · Offline queue

```
┌────────────────────────────────────┐
│  ←  Waiting to sync           12   │
├────────────────────────────────────┤
│  Syncing… 4 of 12                  │
│  ▓▓▓▓▓▓▓░░░░░░░░░░░░               │
├────────────────────────────────────┤
│  ⚠ 2 could not be applied          │
│  ┌──────────────────────────────┐  │
│  │  Boarding · 08:31            │  │
│  │  The bus was full (40/40).   │  │
│  └──────────────────────────────┘  │
│  ┌──────────────────────────────┐  │
│  │  Arrival · Kalyan Nagar      │  │
│  │  The trip closed before this │  │
│  │  synced.                     │  │
│  └──────────────────────────────┘  │
│                                    │
│  Waiting (10)                      │
│  · 8 positions                     │
│  · 2 boardings                     │
├────────────────────────────────────┤
│  ┌──────────────────────────────┐  │
│  │        Retry now             │  │
│  └──────────────────────────────┘  │
└────────────────────────────────────┘
```

Rejections are shown with **why**, in the driver's language. Never a silent drop.

---

## Landscape and tablet

**Phone landscape** — only Map and Boarding support it. Everything else stays portrait; a driver is not filling a form sideways.

**Tablet (≥600dp)** — two-pane on Trip and Map:

```
┌─────────────────┬──────────────────┐
│                 │                  │
│   Trip state    │       Map        │
│   Counter       │                  │
│   Next stop     │                  │
│   Board/Alight  │                  │
│                 │                  │
├─────────────────┴──────────────────┤
│  [ ▲ SOS — hold ]                  │
└────────────────────────────────────┘
```

The counter and Board/Alight keep their size. A bigger screen means more context, **not smaller targets**.

---

## What the wireframes demand

Feeding Phase 6 — these are components because a layout needed them, not because a library has them.

| Recurring element | Appears in |
|---|---|
| Status chip | Trip ×4, stop, incident, queue |
| Big-number display | Running trip, tablet, summary |
| Dual action button (Pass/Fail, Yes/No, Board/Alight) | Inspection, incident, running trip |
| Reason list, grouped by actionability | Blocked, queue rejections |
| Stop card | Trip, map sheet, stop list |
| Persistent banner | Offline, sync |
| GPS pill | Running trip, map |
| Consequence panel | Inspection review, incident |
| Hold-to-activate control | SOS |
| Progress-in-title | Inspection, queue |

**Next:** Phase 6 — Component library.
