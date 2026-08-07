# 12 — Design System

The visual and interaction foundations. Everything in
[11 — UI Component Library](11-ui-component-library.md) is built from these tokens; no
component defines its own colour, spacing or type.

**The rule that makes this document worth having:** a value that is not a token is a bug. No
hex codes, no pixel values, and no font sizes appear in component or screen implementations.

---

## 1. Design principles

Five principles, in priority order. Where they conflict, the higher one wins.

1. **Safety over elegance.** A driver at a bus door and a parent watching for a boarding
   confirmation are the two people this product exists for. If a refined interaction costs
   them a second of clarity, the refinement loses.
2. **State must be unmistakable.** Every status is carried by text and shape, never by colour
   alone. A stale GPS position must be impossible to read as live.
3. **Never a dead end.** Every empty state, error and permission refusal names the next action
   or the person who can help.
4. **Density where it is scanned, space where it is decided.** Operations lists are dense;
   destructive confirmations are spacious.
5. **Consistency beats local optimisation.** A screen that solves its own problem in its own
   way makes the other 206 screens harder to learn.

---

## 2. Typography

### 2.1 Families

| Token | Value | Use |
|---|---|---|
| `font-sans` | Inter, system-ui, sans-serif | All interface text |
| `font-mono` | JetBrains Mono, ui-monospace, monospace | Identifiers, registration numbers, codes, audit diffs, timestamps in dense tables |
| `font-numeric` | `font-sans` with tabular-nums | Any column of figures — counts, capacity, money, times |

**Rule.** Registration numbers, licence numbers and reference codes always render in
`font-mono`. They are read character by character and are frequently transcribed by phone.

### 2.2 Scale

A modular scale at 1.25, anchored on a 16px base.

| Token | Size / line-height | Weight | Use |
|---|---|---|---|
| `text-display` | 40 / 48 | 700 | Kiosk board (AD-09) only |
| `text-h1` | 32 / 40 | 700 | Page title |
| `text-h2` | 25 / 32 | 600 | Section heading |
| `text-h3` | 20 / 28 | 600 | Card heading |
| `text-h4` | 18 / 24 | 600 | Subsection |
| `text-body-lg` | 18 / 28 | 400 | Driver app body, mobile primary |
| `text-body` | 16 / 24 | 400 | Default |
| `text-body-sm` | 14 / 20 | 400 | Table cells, dense lists |
| `text-caption` | 12 / 16 | 400 | Metadata, timestamps, helper text |
| `text-overline` | 11 / 16 | 600, +0.08em | Column headers, chip labels |

**Minimums.** Body text is never below 14px on web or 16px on mobile. The driver app's base
is `text-body-lg` — it is read in daylight glare at arm's length.

### 2.3 Rules

- Maximum measure 75 characters for prose
- Never centre-align more than two lines
- Never use weight alone to convey status
- Truncation always has a tooltip or press-to-reveal; a truncated registration number with no
  way to see it in full is a support call

---

## 3. Colour

### 3.1 Principle

Colour communicates **semantics**, never identity. The palette is defined by role, and roles
map to values per theme. A component references `--color-danger`, never a red.

### 3.2 Neutral ramp

`neutral-0` (white) through `neutral-1000` (near-black), in 11 steps at 50/100/…/900/1000.
Used for text, surfaces, borders and dividers.

| Token | Light theme | Dark theme |
|---|---|---|
| `surface-base` | neutral-0 | neutral-950 |
| `surface-raised` | neutral-0 | neutral-900 |
| `surface-sunken` | neutral-50 | neutral-1000 |
| `surface-overlay` | neutral-0 | neutral-850 |
| `border-subtle` | neutral-200 | neutral-800 |
| `border-strong` | neutral-300 | neutral-700 |
| `text-primary` | neutral-900 | neutral-50 |
| `text-secondary` | neutral-600 | neutral-400 |
| `text-disabled` | neutral-400 | neutral-600 |
| `text-inverse` | neutral-0 | neutral-950 |

### 3.3 Semantic palette

| Role | Meaning | Light | Dark | Used for |
|---|---|---|---|---|
| `primary` | The institution's brand; primary actions | blue-600 | blue-400 | Primary buttons, links, active nav |
| `success` | Healthy, complete, available | green-600 | green-400 | AVAILABLE, COMPLETED, paid |
| `info` | In progress, active now | cyan-600 | cyan-400 | RUNNING, ON_TRIP, live |
| `warning` | Needs attention, not yet failing | amber-600 | amber-400 | MAINTENANCE, expiring, delayed |
| `danger` | Failed, blocked, unsafe | red-600 | red-400 | BREAKDOWN, CANCELLED, expired |
| `critical` | Emergency | red-700 + pulse | red-500 + pulse | SOS only |
| `pending` | Awaiting a decision | violet-600 | violet-400 | SUBMITTED, PENDING_PAYMENT |
| `neutral` | Inactive, not applicable | neutral-500 | neutral-400 | OFFLINE, LEAVE, WITHDRAWN |

**`critical` is reserved for SOS.** Nothing else in the product may use it. Its scarcity is
what makes it work.

### 3.4 Categorical palette (charts)

Eight hues, ordered, chosen to remain distinguishable under deuteranopia and protanopia and to
survive greyscale printing:

`chart-1` blue · `chart-2` orange · `chart-3` teal · `chart-4` magenta · `chart-5` lime ·
`chart-6` purple · `chart-7` brown · `chart-8` grey

Beyond eight series, the chart is wrong — aggregate or facet instead.

### 3.5 Non-negotiable colour rules

1. **Colour is never the sole carrier of meaning.** Every status chip has a text label; every
   chart series has a shape or pattern; every required field has an asterisk as well as a hue.
2. **Contrast minimums:** 4.5:1 for body text, 3:1 for large text and UI boundaries, 3:1 for
   any control's focus indicator.
3. **Both themes are first-class.** Light and dark are specified, built and tested together.
   The driver app additionally ships a high-contrast mode.
4. **The status→semantic mapping is central** (see [11 C-10](11-ui-component-library.md)) and
   may not be overridden per screen.

---

## 4. Spacing

An 8-point grid, with 4 available for tight optical adjustments.

| Token | Value | Use |
|---|---|---|
| `space-0` | 0 | — |
| `space-1` | 4px | Icon-to-label, chip padding |
| `space-2` | 8px | Related elements, dense table cell padding |
| `space-3` | 12px | Form field internal padding |
| `space-4` | 16px | Default gap; card padding (mobile) |
| `space-5` | 24px | Card padding (web); between form fields |
| `space-6` | 32px | Between sections |
| `space-8` | 48px | Between major regions |
| `space-10` | 64px | Page top/bottom margin |
| `space-12` | 96px | Empty-state vertical centring |

**Rules** — Gaps come from the scale, never from arbitrary margins. Vertical rhythm within a
section uses one gap value consistently. Related items are closer than unrelated ones —
proximity does the grouping work before borders do.

---

## 5. Radius, elevation, borders

### 5.1 Radius

| Token | Value | Use |
|---|---|---|
| `radius-none` | 0 | Table cells, full-bleed |
| `radius-sm` | 4px | Chips, badges, inputs |
| `radius-md` | 8px | Buttons, cards |
| `radius-lg` | 12px | Modals, drawers, mobile sheets |
| `radius-xl` | 16px | Mobile cards |
| `radius-full` | 9999px | Avatars, pills, the boarding control |

### 5.2 Elevation

Five levels. Elevation communicates **layer**, not decoration.

| Token | Use |
|---|---|
| `elevation-0` | Flush content |
| `elevation-1` | Cards, raised surfaces |
| `elevation-2` | Dropdowns, popovers, sticky headers |
| `elevation-3` | Drawers, side panels |
| `elevation-4` | Modals, dialogs |
| `elevation-5` | Toasts, the SOS banner |

Dark theme conveys elevation primarily through surface lightness, with shadow as a secondary
cue — shadows are nearly invisible on dark backgrounds.

### 5.3 Borders

`border-width-1` (1px, default) · `border-width-2` (2px, focus and selection) ·
`border-width-4` (4px, left-accent on alerts and banners).

---

## 6. Iconography

- **One family**, outline style, 1.5px stroke, 24×24 grid. No mixing icon sets.
- Sizes: `icon-sm` 16 · `icon-md` 20 · `icon-lg` 24 · `icon-xl` 32 · `icon-2xl` 48 (empty
  states).
- **Icons never appear alone in a control** unless the control is universally understood
  (close, back, overflow) — and even then they carry an accessible label.
- Domain icons are fixed product-wide: bus, driver, student, parent, route, stop, schedule,
  trip, incident, wrench (maintenance), bell (notification), shield (audit), SOS.

---

## 7. Buttons & controls

### 7.1 Button variants

| Variant | Use | Rule |
|---|---|---|
| `primary` | The one main action on a screen | Exactly one per screen or per dialog |
| `secondary` | Alternative actions | Any number |
| `tertiary` / `ghost` | Low-emphasis, in-table actions | — |
| `danger` | Destructive | Always paired with a confirmation (C-31) |
| `link` | Navigation styled as text | Never for a state-changing action |

### 7.2 Sizes and targets

| Token | Height | Min touch target |
|---|---|---|
| `btn-sm` | 32px | 44×44 (with padding) |
| `btn-md` | 40px | 44×44 |
| `btn-lg` | 48px | 48×48 |
| `btn-xl` | 64px | 64×64 — driver boarding control only |

**Touch targets are never below 44×44pt**, regardless of the visual size of the control.

### 7.3 States

default · hover · focus-visible · active · disabled · loading.

**Rules**
- A disabled button **always** exposes its reason on hover or press. "Start trip" disabled with
  no explanation is the single most common source of driver support calls
- Loading state replaces the label with a spinner but preserves the button's width, so the
  layout does not shift
- A submit button cannot fire twice

---

## 8. Responsive breakpoints

| Token | Min width | Target |
|---|---|---|
| `xs` | 0 | Small phones |
| `sm` | 640px | Large phones, small tablets portrait |
| `md` | 768px | Tablets |
| `lg` | 1024px | Small laptops — **admin console minimum** |
| `xl` | 1280px | Desktop |
| `2xl` | 1536px | Large desktop, operations wall displays |

### 8.1 Behaviour by surface

- **Admin console** — designed at `xl`, fully functional from `lg`. Below `lg` it degrades
  gracefully to a read-and-triage experience: lists, detail and alert acknowledgement work;
  the timetable grid, stop manager and roster board are explicitly not supported and say so.
- **Mobile apps** — designed at `xs`, adapting up. Tablet layouts use the space for a split
  view rather than stretched single columns.
- **Kiosk / wall board** — `2xl` only, `text-display`, no interactive controls.

### 8.2 Adaptive rules

- Data tables (C-07) become card lists below `md`
- Split views (C-05) become stacked navigation below `md`
- Sidebars (C-36) collapse to icons at `lg`, to a drawer below it
- Modals become full-screen sheets below `sm`
- Multi-column forms become single-column below `md`

---

## 9. Motion

Motion exists to explain change, never to decorate.

| Token | Duration | Easing | Use |
|---|---|---|---|
| `motion-instant` | 0ms | — | State that must feel immediate: boarding count |
| `motion-fast` | 100ms | ease-out | Hover, focus, small state changes |
| `motion-base` | 200ms | ease-in-out | Dropdowns, tooltips, chips |
| `motion-moderate` | 300ms | ease-in-out | Drawers, modals, page transitions |
| `motion-slow` | 500ms | ease-in-out | Skeleton shimmer, onboarding |
| `motion-marker` | 1000ms | linear | Bus marker interpolation between positions |

### 9.1 Rules

- **`prefers-reduced-motion` is honoured absolutely.** All non-essential motion is removed;
  bus markers jump rather than animate; nothing loses function
- Never animate a value the user is reading. A count that is being watched updates instantly
- The bus marker's 1000ms linear interpolation matches the position cadence, so movement reads
  as continuous travel rather than as a series of jumps
- The SOS banner pulses; it is the only pulsing element in the product
- Loading skeletons shimmer at `motion-slow`; anything faster reads as agitation

---

## 10. Accessibility standards

**Target: WCAG 2.2 Level AA**, with the specific commitments below. These are acceptance
criteria, not aspirations — see [16](16-module-completion-criteria.md).

### 10.1 Perceivable
- Contrast: 4.5:1 body, 3:1 large text and UI boundaries
- Colour never sole carrier of meaning
- All non-decorative images have text alternatives; decorative ones are hidden from assistive
  technology
- Content reflows at 320px width and at 200% zoom without horizontal scrolling
- Text spacing can be overridden without loss of content

### 10.2 Operable
- Every function is keyboard-reachable, in a logical order, with no traps
- Focus indicator is visible at 3:1 contrast and is never removed
- Skip-to-content on every page
- Touch targets ≥44×44pt with ≥8px separation
- No time limit on any safety-relevant action; session-expiry warnings are given with the
  ability to extend
- Nothing flashes more than three times per second

### 10.3 Understandable
- Labels are visible, not placeholder-only
- Errors are identified in text, describe the fix, and move focus to the first offending field
- Destructive actions are reversible, confirmed, or both
- Language of the page and of any part in another language is declared

### 10.4 Robust
- Semantic structure; ARIA only where semantics are insufficient
- Status messages announced via live regions — including the boarding count, the arrival of a
  new alert, and connection-state changes
- Tested with a screen reader on each platform, and by keyboard only, before a module is
  accepted

### 10.5 Driver app specific
- High-contrast mode and large-text mode ship with the app
- One-handed operation: every in-trip action reachable in the lower two-thirds of the screen
- Haptic feedback on the boarding control and on SOS arming
- Readable in direct sunlight: the in-trip surface uses the highest-contrast pairing available

---

## 11. Localisation

- All strings externalised; no concatenated sentences (they cannot be translated correctly)
- Layouts tolerate 40% text expansion without truncation
- Right-to-left support: logical properties throughout, mirrored icons where directional
- Dates, times, numbers and currency formatted per locale; timezone labelled where ambiguous
- Names are not assumed to have a given/family structure
- The student and parent apps ship in the institution's local languages; the admin console may
  ship in fewer

---

## 12. Token governance

- Tokens are defined once, in a single source, and consumed by every client
- A component may not introduce a raw value; a screen may not override a component's tokens
- Adding a token requires a design review; adding a *semantic* role requires a stronger case
  than adding a value to an existing ramp
- Theme values change; token names do not. A rebrand touches the value table in §3 and nothing
  else

**Enforcement** — the pull-request checklist in [15 §12](15-developer-handbook.md) rejects raw
hex values, raw pixel spacing, and font sizes outside the scale.
