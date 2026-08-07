# Driver App — Phase 4: State Machines

**Derived from:** [03 — Screen inventory](03-screen-inventory.md)
**Purpose:** these states *are* the Bloc/Cubit definitions. Every state below becomes a sealed class; every transition becomes an event.

---

## Why this document exists

The state most teams forget is not `loading` or `error`. It is the one where the app has **stale data it is still showing**, or **a queued write the server has not seen**. Those are the states a driver actually spends time in, and an app that models only `loading → success → error` will render a confident screen over data that is eight minutes old.

Three states appear in almost every machine here and are the reason it is worth writing down:

- **`ready(stale)`** — we have data, we know it is old, we are showing it anyway because showing nothing is worse
- **`ready(pending)`** — the driver's action is applied locally and not yet on the server
- **`degraded`** — a subsystem failed but the task continues (GPS lost, notifications denied)

---

## M0 · App session

Global. Wraps everything.

```
                    ┌─────────────┐
                    │ initialising│
                    └──────┬──────┘
                           │
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
  ┌────────────┐   ┌──────────────┐   ┌────────────┐
  │unauthenticated│ │ authenticated│   │  offline   │
  └──────┬─────┘   └──────┬───────┘   │ (cached id)│
         │                │            └─────┬──────┘
         │  login ok      │ 401 + refresh ok │ restore
         └───────────────►│◄─────────────────┘
                          │
                          │ 401 + refresh fails
                          ▼
                   ┌─────────────┐
                   │   expired   │──► unauthenticated
                   └─────────────┘
```

| State | Data held | UI |
|---|---|---|
| `initialising` | none | Splash |
| `unauthenticated` | none | Login |
| `authenticated` | user, profile, token pair | App shell |
| `offline` | cached user | App shell + offline banner |
| `expired` | none | Session expired, stack cleared |

**Transitions**

| From | Event | To | Side effect |
|---|---|---|---|
| `initialising` | no token | `unauthenticated` | — |
| `initialising` | token + `/me` 200 | `authenticated` | cache identity |
| `initialising` | token + no network | `offline` | — |
| `authenticated` | any 401 | `authenticated` | refresh **once** |
| `authenticated` | refresh 401 | `expired` | clear tokens, clear stack |
| `authenticated` | logout | `unauthenticated` | `POST /auth/logout`, clear queue? **no** — see note |

> **Logout does not clear the offline queue.** A driver who logs out with unsynced boardings must not silently destroy them. Warn, and refuse until synced or explicitly discarded.

---

## M1 · Trip

The most important machine in the app. Mirrors `TripStatus` server-side, plus client-only states the server has no concept of.

```
        ┌──────────┐
        │ loading  │
        └────┬─────┘
             │
    ┌────────┼────────┬──────────┬───────────┐
    ▼        ▼        ▼          ▼           ▼
┌───────┐┌────────┐┌────────┐┌─────────┐┌─────────┐
│ none  ││blocked ││ ready  ││ running ││ closed  │
└───────┘└───┬────┘└───┬────┘└────┬────┘└─────────┘
             │         │          │
        inspection  start ok      │ complete
             └────────►│          └──────────►closed
                       │
                  start refused
                       └──────────► blocked
```

| State | Meaning | Server truth |
|---|---|---|
| `loading` | fetching | — |
| `none` | no trip today | empty `/trips` |
| `blocked` | trip exists, bus not cleared | `SCHEDULED` + readiness false |
| `ready` | cleared to start | `SCHEDULED` + readiness true |
| `running` | in progress | `RUNNING` |
| `closed` | finished or cancelled | `COMPLETED` / `CANCELLED` |
| `waiting` | cleared but outside start window | `SCHEDULED`, 409 window |

`blocked` carries `reasons: List<BlockReason>` where each reason is `{text, actionable}`. `actionable` is true **only** for the missing-inspection reason — that single flag drives whether the screen offers a button.

**Transitions**

| From | Event | To |
|---|---|---|
| `loading` | trips empty | `none` |
| `loading` | `RUNNING` found | `running` (**resume**, restart GPS) |
| `blocked` | inspection passes | `ready` |
| `ready` | start 200 | `running` |
| `ready` | start 409 window | `waiting` (timer → `ready`) |
| `ready` | start 409 other | `blocked` |
| `running` | complete 200 | `closed` |
| `running` | live poll says not RUNNING | `closed` (**cancelled underneath**) |

> A trip cancelled by a consolidation moves `running → closed` without the driver acting. Explain it; never fail silently.

---

## M2 · Inspection

```
   idle ──► loading checklist ──► editing
                                    │
                    ┌───────────────┼──────────────┐
                    ▼               ▼              ▼
              item passed     item failed     odometer invalid
                    │               │              │
                    │      safety-critical?        └──► editing (error)
                    │               │
                    │          ┌────┴────┐
                    │          ▼         ▼
                    │      capturing  attached
                    │          │         │
                    └──────────┴────►  editing
                                        │
                                   all answered
                                        ▼
                                    reviewing
                                        │
                                   submitting
                    ┌───────────────────┼──────────────┐
                    ▼                   ▼              ▼
                submitted           rejected      queued(offline)
                (outcome)          (422/409)
```

| State | Notes |
|---|---|
| `editing` | Holds `Map<InspectionItem, ItemVerdict>` — the draft. **Persisted locally on every change** |
| `capturing` | Evidence sub-flow owns the screen (M1) |
| `reviewing` | All items answered; shows the consequence if any critical item failed |
| `submitting` | Blocking, non-cancellable |
| `submitted` | Carries the **server-decided** outcome |
| `queued` | Offline; bus is **not** cleared |

**The draft survives everything.** App kill, phone restart, battery death. A driver re-entering finds their checklist where they left it.

---

## M3 · GPS stream

Runs for the entire life of a `running` trip. Independent of whichever screen is visible.

```
        ┌──────────┐
        │  idle    │
        └────┬─────┘
             │ trip → running
             ▼
     ┌───────────────┐  fix acquired  ┌──────────┐
     │   acquiring   │───────────────►│  live    │
     └───────┬───────┘                └────┬─────┘
             │                             │
             │ no fix 30s                  │ post fails / offline
             ▼                             ▼
     ┌───────────────┐                ┌──────────┐
     │  no signal    │◄───────────────│ buffering│
     └───────┬───────┘   still failing└────┬─────┘
             │                             │ network back
             └────────────► acquiring ◄────┘ replay
```

| State | Posting | Buffer | UI |
|---|---|---|---|
| `idle` | no | — | hidden |
| `acquiring` | no | — | pill: "Finding position" |
| `live` | every 5–10s | empty | pill: green |
| `buffering` | no | growing | pill: amber + count |
| `no signal` | no | growing | pill: grey |

**Never a dialog. Never blocks. Never stops the trip.**

Replay is throttled to stay under **60/min**: send in batches with a minimum 1s gap, oldest first, dropping nothing. A 90-minute outage produces ~700 buffered fixes; at 50/min that is 14 minutes of replay, running in the background while the driver keeps working.

| Response during replay | Action |
|---|---|
| 200 | drop from buffer |
| 409 implausible | drop silently — server is right |
| 422 outside service area | drop, increment a counter shown in the queue |
| 409 trip not running | **stop everything**, purge buffer, re-fetch trip |
| 429 | pause 60s, resume |

---

## M4 · Boarding counter

```
  ready(count) ──► optimistic(count±1) ──► confirmed(serverCount)
                            │                      │
                            │ queued offline       │
                            ▼                      │
                     pending(count, n queued) ─────┘ on sync
                            │
                            │ sync rejects
                            ▼
                     reconciled(serverCount, rejected: n)
```

| State | Display |
|---|---|
| `ready` | count, capacity, both from server |
| `optimistic` | count updated instantly, subtle pending mark |
| `pending` | count + "n not yet synced" |
| `confirmed` | server count, pending mark cleared |
| `reconciled` | server count + **explicit note** of how many failed |

`reconciled` is the state most apps omit. If a driver counted 23 and 19 landed, the difference is shown — not smoothed over. The backend raises an attendance discrepancy for it (BR-266); the app's job is to not hide it.

---

## M5 · SOS

The only machine where a failure state is deliberately unreachable.

```
     idle ──hold 1.5s──► confirming ──cancel──► idle
                             │
                          confirm
                             ▼
                    ┌─────────────────┐
                    │ persisted-local │  ← BEFORE any network call
                    └────────┬────────┘
                             │
                   ┌─────────┴─────────┐
                   ▼                   ▼
              sending              (offline)
                   │                   │
          ┌────────┴────────┐          │
          ▼                 ▼          ▼
      acknowledged      retrying ──► queued
          │                 ▲          │
          │                 └──────────┘ backoff
          ▼
      active ──cancel+note──► cancelled(recorded)
```

| State | UI | Fallbacks offered |
|---|---|---|
| `persisted-local` | "Alerting…" | — (momentary) |
| `sending` | "Alerting…" | — |
| `queued` | **"Alert saved. Will send when you have signal."** | 📞 Call · ✉ SMS |
| `retrying` | same as queued | same |
| `active` | "Operations alerted at {time}" | 📞 Call |
| `cancelled` | "Withdrawn — recorded" | — |

**There is no `failed`.** Retry continues across app restarts until acknowledged or cancelled.

---

## M6 · Sync queue

```
   empty ──enqueue──► pending(n)
                         │
              network ──►│
                         ▼
                     syncing(n, done)
                         │
              ┌──────────┼──────────┐
              ▼          ▼          ▼
           empty    partial(f)   pending(n)
                         │        (still offline)
                    user reviews
                         ▼
                       empty
```

| State | Banner |
|---|---|
| `empty` | none |
| `pending(n)` | "n changes waiting to sync" |
| `syncing` | progress |
| `partial(f)` | ⚠ "f changes could not be applied" → opens M3 |

Ordering is **strictly FIFO per trip**. A boarding recorded before an arrival must replay in that order or the server rejects it for the wrong reason.

---

## M7 · Connectivity

Cross-cutting; every machine above subscribes to it.

```
   online ◄──restored── offline ◄──lost── online
      │                    │
      └─► triggers M6 sync │
                           └─► GPS → buffering, reads → cache
```

Distinguish **no connectivity** from **server unreachable**. A driver on hotel wi-fi with no route to the API is not offline in the OS sense, but is offline for our purposes. Treat any network error or 5xx on three consecutive calls as offline.

---

## M8 · Evidence upload

```
  idle ──► permission ──denied──► blocked
             │
           granted
             ▼
         capturing ──► previewing ──retake──► capturing
                           │
                          use
                           ▼
                       uploading
              ┌────────────┼────────────┐
              ▼            ▼            ▼
          uploaded     rejected      queued
          (has id)   (mime/size)   (offline)
```

`queued` here is special: the evidence **and its parent report** queue as one unit, because the report cannot cite an id that does not exist yet. This is the only compound transaction in the queue.

---

## Bloc mapping

One-to-one with the machines above.

| Bloc / Cubit | Machine | Scope |
|---|---|---|
| `SessionBloc` | M0 | App |
| `TripBloc` | M1 | App (survives tab switches) |
| `InspectionBloc` | M2 | Inspection flow |
| `GpsStreamBloc` | M3 | App, active while running |
| `BoardingCubit` | M4 | Stop / boarding screen |
| `SosBloc` | M5 | App — must outlive its screen |
| `SyncQueueBloc` | M6 | App |
| `ConnectivityCubit` | M7 | App |
| `EvidenceBloc` | M8 | Evidence modal |

**App-scoped** blocs (`Session`, `Trip`, `Gps`, `Sos`, `Sync`, `Connectivity`) are provided above the router. A driver switching tabs mid-trip must not restart the GPS stream, and an SOS must survive its screen being popped.

**Next:** Phase 5 — Wireframes.
