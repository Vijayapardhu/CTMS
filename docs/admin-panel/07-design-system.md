# Design system

Inherited from the driver app, then made denser. The two products sit on the
same laptop during a demo and must look like one system.

## Colour

Primary is CTMS blue. **No orange.** The brand can change later, in one place,
across both products.

```text
primary            #0B57D0     ColorScheme.primary, light
primaryContainer   #D3E3FD
primary (dark)     #A8C7FA
primaryContainer   #0842A0     dark
```

Semantic colours are the driver app's `CtmsColors`, unchanged. They are paired
— a fill and the foreground that is legible on it — and the pairing is not
optional. The driver app shipped white text on a light pastel in dark mode and
it was unreadable; the pairs exist so that cannot happen twice.

| Token | Light | On | Dark | On | Used for |
|---|---|---|---|---|---|
| `positive` | `#146C2E` | `#FFFFFF` | `#7FD98F` | `#00390F` | Available, passed, running on time |
| `caution` | `#8A5000` | `#FFFFFF` | `#FFB868` | `#4A2800` | Delayed, defects, due soon |
| `critical` | `#B3261E` | `#FFFFFF` | `#FFB4AB` | `#690005` | Blocked, failed, breakdown |
| `neutral` | `#5F6368` | `#FFFFFF` | `#9AA0A6` | `#1F2124` | Cancelled, inactive, skipped |
| `info` | `#00639B` | `#FFFFFF` | `#8ECFF8` | `#003353` | Completed, informational |
| `emergency` | `#8C1D18` | `#FFFFFF` | `#F2B8B5` | `#601410` | SOS only |
| `liveAccent` | `#00A63E` | — | `#5CE07A` | — | Fresh position |
| `staleAccent` | `#9AA0A6` | — | `#6F7378` | — | Stale position |
| `mapRoute` | `#B30B57D0` | — | `#B3A8C7FA` | — | Route ahead |
| `mapRouteDone` | `#665F6368` | — | `#669AA0A6` | — | Route completed |

`emergency` is darker than `critical` on purpose. If SOS looks like every other
warning it stops being findable.

### Status → colour

| Domain | Value | Token |
|---|---|---|
| Trip | `RUNNING` | `positive` |
| Trip | `SCHEDULED` | `info` |
| Trip | `COMPLETED` | `neutral` |
| Trip | `CANCELLED` | `critical` |
| Bus | `AVAILABLE` | `positive` |
| Bus | `RUNNING` | `info` |
| Bus | `MAINTENANCE` | `caution` |
| Bus | `BREAKDOWN` | `critical` |
| Bus | `OFFLINE` | `neutral` |
| Incident | `CRITICAL` severity | `emergency` |
| Incident | `HIGH` | `critical` |
| Incident | `MEDIUM` | `caution` |
| Incident | `LOW` | `info` |
| Incident | `RESOLVED` / `CLOSED` | `neutral` |
| Maintenance | `URGENT` | `critical` |
| Maintenance | `OPEN` / `SCHEDULED` | `caution` |
| Maintenance | `IN_PROGRESS` | `info` |
| Maintenance | `COMPLETED` | `positive` |
| Driver | `AVAILABLE` | `positive` |
| Driver | `ON_TRIP` | `info` |
| Driver | `LEAVE` / `OFF_DUTY` | `neutral` |

## Typography

One family. Denser than the driver app: the phone is read at arm's length in
sunlight, the panel at 60 cm indoors.

| Role | Size / line | Weight | Used for |
|---|---|---|---|
| `display` | 28 / 36 | 600 | Dashboard metric numbers |
| `titleLg` | 20 / 28 | 600 | Page titles |
| `titleMd` | 16 / 24 | 600 | Card and drawer headers |
| `body` | 14 / 20 | 400 | Default, table cells |
| `bodyStrong` | 14 / 20 | 600 | Emphasised cells |
| `label` | 12 / 16 | 500 | Column headers, chips |
| `mono` | 13 / 20 | 400 | Registrations, ids, timestamps |

Registrations, UUID fragments and timestamps are monospace. A column of
registrations in a proportional face cannot be scanned.

## Spacing, radius, elevation

Denser than the driver app's 8-point rhythm; 4 is the unit.

```text
xs 4    sm 8    md 12    lg 16    xl 24    xxl 32
```

| Radius | Value | Used for |
|---|---|---|
| `sm` | 4 | Chips, inputs |
| `md` | 8 | Cards, drawers, dialogs |
| `lg` | 12 | Map panel |

| Elevation | Used for |
|---|---|
| 0 | Page surface, table |
| 1 | Cards, top bar on scroll |
| 2 | Drawers |
| 3 | Dialogs, menus |

Tonal elevation, not shadows — the driver app's rule, kept for consistency.

## Density

| Element | Height |
|---|---|
| Table row | 44 |
| Table header | 40 |
| Toolbar / filter bar | 52 |
| Top bar | 56 |
| Sidebar item | 40 |
| Button | 36 (compact 32) |
| Input | 36 |

44 for a row is the compromise: 32 is a spreadsheet nobody can click, 56 wastes
a third of the fold. Interactive targets are never smaller than 32 × 32, and
row-level actions are 36 × 36.

## Layout

Primary target **1440 × 900**.

```text
sidebar     240 expanded · 64 collapsed
content     fluid, max 1600, gutters 24
drawer      480 · 640 for evidence
dialog      420 · 560 for forms
```

| Width | Behaviour |
|---|---|
| ≥ 1440 | Full layout, drawer beside content |
| 1280–1439 | Sidebar collapses to icons; drawer overlays |
| 1024–1279 | Sidebar off-canvas; tables drop low-priority columns |
| < 1024 | Supported, not optimised. Cards replace tables; the map fills the viewport |

The panel is not built for phones. A transport head with a phone has the driver
app's read-only screens and a laptop on their desk.

## Motion

An operations console that animates is an operations console that lies about
how fresh its data is.

```text
instant   0ms      state chips, counters, table sorts
fast      120ms    hover, focus, menu
standard  200ms    drawer in/out, dialog
```

Nothing else moves. No skeleton shimmer on refresh — only on first load. No
animated number counting. No map fly-to longer than 300 ms.

**A value that changes must not animate.** A count going 3 → 4 changes
instantly, because a tween invites the eye to a number that is already stale.

## Dark theme

Both themes are first-class; a transport office at 6 a.m. in winter is a dark
room. Every semantic colour has its dark pair above, and the rule from the
driver app applies: **never define a colour only in one theme**, and never put
a foreground on a fill without using its pair.
