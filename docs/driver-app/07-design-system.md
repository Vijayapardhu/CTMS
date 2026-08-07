# Driver App — Phase 7: Design System

**Derived from:** [06 — Component library](06-component-library.md)
**Sized to what the components actually need.** Every token below is used by at least one component. Tokens nothing uses are not defined, because an unused token becomes a wrong one.

---

## What the components demanded

Working backwards from Phase 6:

- **3 button variants**, not 6 — primary, secondary, danger. No tertiary, no text-button-with-icon variant; nothing needed one.
- **4 semantic statuses**, not 10 — positive, caution, critical, neutral. Every chip variant maps onto these four.
- **3 elevations**, not 5 — flat, raised, floating. There is no scenario with a card on a card on a sheet.
- **6 type sizes**, not 13 — because the app has exactly six jobs for text.
- **1 accent for live data**, because live-vs-stale is the only visual distinction the map needs.

---

## Colour

### Rules

1. **Semantic names only.** No `red500`. A developer writing `AppColors.critical` cannot use it for decoration.
2. **Never colour alone.** Every semantic colour ships with a required icon. ~8% of male drivers have a colour vision deficiency.
3. **Sunlight first.** Contrast targets exceed WCAG AA because the reference environment is a windscreen at midday, not an office.

### Palette

| Token | Light | Dark | Contrast (on surface) | Used by |
|---|---|---|---|---|
| `primary` | `#0B57D0` · rgb(11,87,208) | `#A8C7FA` · rgb(168,199,250) | 7.4:1 / 9.1:1 | Primary buttons, active nav, links |
| `onPrimary` | `#FFFFFF` | `#062E6F` | — | Text on primary |
| `primaryContainer` | `#D3E3FD` | `#0842A0` | — | Selected states, chips |
| `secondary` | `#3B5F8A` | `#9FC0E8` | 6.2:1 | Secondary buttons, map route line |
| **`positive`** | `#146C2E` · rgb(20,108,46) | `#7FD98F` | 6.9:1 / 10.2:1 | Ready, passed, live GPS, boarded |
| **`caution`** | `#8A5000` · rgb(138,80,0) | `#FFB868` | 6.1:1 / 9.8:1 | Delayed, buffering, defects, pending sync |
| **`critical`** | `#B3261E` · rgb(179,38,30) | `#FFB4AB` | 6.4:1 / 8.7:1 | SOS, failed, blocked, capacity full |
| **`neutral`** | `#5F6368` | `#9AA0A6` | 5.4:1 | Offline, disabled, skipped, unknown |
| `info` | `#00639B` | `#8ECFF8` | 6.0:1 | Completed, informational banners |
| `surface` | `#FFFFFF` | `#131314` | — | Screen background |
| `surfaceContainer` | `#F1F3F4` | `#1E1F20` | — | Cards |
| `surfaceContainerHigh` | `#E8EAED` | `#282A2C` | — | Sheets, dialogs |
| `outline` | `#C4C7C5` | `#444746` | 3.1:1 | Borders, dividers |
| `onSurface` | `#1F1F1F` | `#E3E3E3` | 15.8:1 / 14.2:1 | Body text |
| `onSurfaceVariant` | `#444746` | `#C4C7C5` | 9.2:1 | Secondary text, labels |
| `disabled` | `#1F1F1F` @ 38% | `#E3E3E3` @ 38% | — | Disabled content |
| `scrim` | `#000000` @ 40% | `#000000` @ 60% | — | Modal backdrop |

### Domain accents

Distinct from the semantic four because they mean something the four cannot express.

| Token | Light | Dark | Meaning |
|---|---|---|---|
| `liveAccent` | `#00A63E` | `#5CE07A` | Position is fresh. **Only** for live GPS |
| `staleAccent` | `#9AA0A6` | `#6F7378` | Position is `is_stale`. Desaturated on purpose |
| `mapRoute` | `#0B57D0` @ 70% | `#A8C7FA` @ 70% | Route polyline |
| `mapRouteDone` | `#5F6368` @ 40% | `#9AA0A6` @ 40% | Segment already driven |
| `geofence` | `#0B57D0` @ 12% fill, 40% stroke | same | Stop radius |
| `emergency` | `#8C1D18` | `#F2B8B5` | SOS chrome only — never anything else |

`emergency` is deliberately darker than `critical`. The SOS control must not look like every other warning in the app, or it stops being findable in a panic.

### Status → colour mapping

| Domain state | Token | Required icon |
|---|---|---|
| Trip ready · inspection passed · GPS live · boarded | `positive` | check-circle |
| Trip delayed · passed-with-defects · buffering · pending sync | `caution` | alert-triangle |
| Trip blocked · inspection failed · SOS · capacity full | `critical` | alert-circle |
| Offline · skipped · disabled · unknown | `neutral` | minus-circle |
| Trip completed · informational | `info` | information-circle |

---

## Typography

Six sizes. Roboto (Material 3 default) — no custom font, because a font file is a startup cost and a licence question for zero operational gain.

| Token | Size / Line | Weight | Tracking | Used by |
|---|---|---|---|---|
| `display` | 57 / 64 | 400 | −0.25 | `BigNumberDisplay` only |
| `headline` | 28 / 36 | 400 | 0 | Screen titles, empty-state titles |
| `title` | 20 / 28 | 500 | +0.15 | Card titles, registration number, sheet titles |
| `body` | 16 / 24 | 400 | +0.5 | **Default.** All prose, list content |
| `label` | 14 / 20 | 500 | +0.1 | Buttons, chips, field labels |
| `caption` | 12 / 16 | 400 | +0.4 | Timestamps, helper text, "estimated" |

**Nothing below 12sp exists.** If content does not fit at 12sp, the content is wrong.

### Numeric treatment

`display` and `title` use **tabular figures** (`FontFeature.tabularFigures()`). Without it, a counter ticking 19 → 20 shifts horizontally and reads as a glitch on a moving vehicle.

Timestamps use tabular figures at `caption`.

### Scaling

Honour the system text scale up to **1.5×**. Above that, layouts reflow to single-column and the `display` size caps — the counter must stay on one line at any scale, because a wrapped "23 / 40" is unreadable.

---

## Spacing

8pt grid. Six values in real use.

| Token | Value | Used for |
|---|---|---|
| `xs` | 4 | Icon-to-label, chip padding |
| `sm` | 8 | Between related controls — the minimum gap between touch targets |
| `md` | 16 | **Default.** Screen padding, card padding, between cards |
| `lg` | 24 | Between sections |
| `xl` | 32 | Above a primary action, around empty states |
| `xxl` | 48 | Top of empty states, around the SOS control |

`12`, `20`, `40` and `64` from the original brief are **not defined**. Nothing in Phase 5 needed them, and offering them guarantees inconsistent use.

---

## Radius

| Token | Value | Used by |
|---|---|---|
| `sm` | 8 | Chips, input fields, small tiles |
| `md` | 16 | Cards, buttons, evidence thumbnails |
| `lg` | 28 | Sheets (top corners), dialogs |
| `full` | 999 | GPS pill, badges, `HoldToActivate` ring |

---

## Elevation

Material 3 tonal elevation, not shadows — shadows wash out in sunlight.

| Token | Level | Tonal overlay | Used by |
|---|---|---|---|
| `flat` | 0 | none | Screen background, inline cards |
| `raised` | 1 | `surfaceContainer` | Cards, banners |
| `floating` | 3 | `surfaceContainerHigh` | Sheets, dialogs, SOS chrome, map controls |

**Three levels. No more.** The map's floating controls and the SOS bar share `floating`; nothing needs to sit above them.

---

## Motion

### Principle

Animation conveys **state change or spatial relationship**. Nothing decorative. Every duration below is short enough that a driver glancing away and back does not miss it.

| Motion | Duration | Curve | Notes |
|---|---|---|---|
| Screen push / pop | 300 / 250 ms | `easeInOutCubic` | Material shared-axis X |
| Modal open / close | 250 / 200 ms | `easeOutCubic` | Shared-axis Z |
| Bottom sheet | 300 / 200 ms | `easeOutCubic` | |
| Dialog | 200 / 150 ms | `easeOut` | Scale from 0.9 |
| Banner in / out | 200 / 150 ms | `easeOut` | Height, pushes content |
| Snackbar | 200 ms, 4 s dwell | `easeOut` | 6 s if it has an action |
| **Counter increment** | 180 ms | `easeOutBack` | Number slides up; **no crossfade** |
| **GPS pulse (acquiring)** | 1600 ms loop | `easeInOut` | Slow — a fast pulse reads as alarm |
| **SOS hold ring** | 1500 ms | `linear` | Must be linear; easing misrepresents progress |
| **SOS active pulse** | 2000 ms loop | `easeInOut` | Subtle. Opacity 1.0 → 0.75 |
| Sync rotation | 1000 ms loop | `linear` | |
| Skeleton shimmer | 1200 ms loop | `easeInOut` | |
| Success check draw | 400 ms | `easeOutCubic` | Stroke draw, once |
| Error shake | 300 ms | `elasticOut` | 8dp amplitude, twice |
| Map camera follow | 600 ms | `easeInOutCubic` | Never instant — a snapping camera is disorienting |
| Marker position | 1000 ms | `linear` | Interpolates between fixes so the bus glides |

### Reduce motion

When the system flag is on: all durations → **0 ms** except banner (kept at 200ms so its appearance is noticed), skeleton → static block, pulses → static, marker → instant.

---

## Dark mode

Not an inversion. Two real differences:

1. **Elevated surfaces get lighter, not darker.** `surfaceContainerHigh` is `#282A2C` — Material 3 tonal elevation.
2. **Semantic colours desaturate and lighten** to hold contrast on dark: `critical` moves `#B3261E → #FFB4AB`.

The **map stays in light style during daylight hours** regardless of app theme, and switches to dark style after sunset. A dark map at midday is unreadable through a windscreen. Follow device sunrise/sunset, not the app theme.

---

## Theme extension

```dart
@immutable
class CtmsColors extends ThemeExtension<CtmsColors> {
  final Color positive, caution, critical, neutral, info;
  final Color liveAccent, staleAccent, emergency;
  final Color mapRoute, mapRouteDone, geofence;
  // …copyWith, lerp
}
```

Semantic colours live in an extension rather than being bent into `ColorScheme`, because `ColorScheme.error` is one slot and this app needs four distinct statuses that are all "not primary".

---

## Density

One density. No compact mode, no comfortable mode. A driver's phone and a supervisor's tablet render the same target sizes — a larger screen shows *more*, never *smaller*.

**Next:** Phase 8 — Icon registry.
