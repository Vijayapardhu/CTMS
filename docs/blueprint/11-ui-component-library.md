# 11 — UI Component Library

The catalogue of every reusable interface element. **A screen specification may not invent a
component.** If a screen needs something not in this document, the component is added here
first, with its states and rules, and then referenced.

Component identifiers (`C-xx`) are stable and referenced from the screen specs in documents
03–07.

---

## How to use this document

Every component entry defines:

- **Purpose** — the one job it does
- **Anatomy** — its parts
- **Variants** — the permitted forms
- **States** — default, hover, focus, active, disabled, loading, error, empty
- **Rules** — behaviour that is not negotiable per-screen
- **Used by** — screens that consume it

**Platform note.** Components are specified behaviourally, not per framework. The admin
console (web) and the three mobile apps implement the same component contract with
platform-native affordances. Where behaviour must differ by platform, it is stated.

---

## 1. Layout & structure

### C-01 · App Shell
**Purpose** — The persistent frame every authenticated screen lives inside.
**Anatomy** — Sidebar (web) or tab bar (mobile) · top bar · content region · toast region ·
modal region.
**Variants** — `web-full` (sidebar expanded) · `web-collapsed` · `mobile-tabs` ·
`mobile-focus` (chrome hidden, used by DR-05 Active Trip) · `kiosk` (no chrome, AD-09).
**Rules**
- The alert bell and global search are reachable from every authenticated screen
- Navigation items the role cannot reach are absent, not disabled
- An active SOS renders a persistent banner in the shell itself, above content, on every staff
  screen — it is a property of the shell so no screen can fail to show it
- `mobile-focus` still exposes the SOS control

### C-02 · Page Header
**Anatomy** — Breadcrumb · title · subtitle/metadata · status chip · primary action ·
overflow menu.
**Rules** — Breadcrumb is mandatory below depth 1. Exactly one primary action; everything
else goes in the overflow. The record's status chip lives here, not buried in the body.

### C-03 · Section Card
**Purpose** — Groups related content on a detail screen.
**Variants** — `default` · `bordered` · `elevated` · `inline-editable` · `collapsible`.
**States** — Loading renders a skeleton of its own shape, not a spinner.

### C-04 · Tab Set
**Rules** — Tab state is held in the URL on web. Tabs load lazily but preserve state when
revisited. A tab the role cannot see is absent, not empty. Never more than 7 tabs; beyond
that the screen is doing too much.

### C-05 · Split View
**Purpose** — List plus detail side by side (AD-02 map + panel, AD-05 alerts).
**Rules** — Collapses to stacked navigation below the `md` breakpoint. Selection is reflected
in the URL.

### C-06 · Drawer / Side Panel
**Variants** — `right` (detail preview) · `bottom` (mobile actions).
**Rules** — Dismissible by escape, backdrop tap, and an explicit close. Never contains the
only route to an action. Does not trap focus permanently.

---

## 2. Data display

### C-07 · Data Table
**Purpose** — Every list on web.
**Anatomy** — Column headers (sortable) · rows · row overflow menu · selection checkboxes ·
pagination footer · column visibility control · density control.
**States** — Loading (skeleton rows matching column widths) · empty (C-24) · error (C-25) ·
partially loaded · all-selected banner.
**Rules**
- Server-side sort, filter and pagination. Always. No table ever loads an unbounded set
- Sort, filter, page and column state are held in the URL
- Row click opens detail; row actions live in the overflow menu, never as a button row
- Bulk selection offers "select all N matching filters", and states the count before any bulk
  action runs
- Column visibility and density persist per user per table
- Horizontally scrollable below `lg`, with the identity column pinned

### C-08 · List Item (mobile)
**Variants** — `single-line` · `two-line` · `three-line` · `with-media` · `with-status`.
**Rules** — Primary text is the identity. Status is conveyed by chip, never colour alone.
Swipe actions must have a non-swipe equivalent in the overflow.

### C-09 · Key-Value List
**Purpose** — Read display of record attributes.
**Rules** — Empty values render as an explicit "Not set", never as blank space. Fields the
role may not see are absent, not masked, unless the *existence* of the value is itself
meaningful — in which case they render as "Restricted".

### C-10 · Status Chip
**Purpose** — Renders any state-machine value.
**Variants** — By semantic: `neutral` · `success` · `info` · `warning` · `danger` · `pending`.
**Rules**
- **Colour is never the sole carrier of meaning.** Every chip has a text label; icon-only chips
  are prohibited
- The mapping from domain state to semantic is fixed centrally, not per screen — `BREAKDOWN`
  looks the same everywhere in the product
- A chip for a blocking state (expired document, suspended pass) carries a `!` glyph in
  addition to its colour

**Canonical mappings**

| Domain | Value → semantic |
|---|---|
| Bus | AVAILABLE→success · RUNNING→info · MAINTENANCE→warning · BREAKDOWN→danger · OFFLINE→neutral |
| Driver | AVAILABLE→success · ON_TRIP→info · LEAVE→neutral · OFF_DUTY→neutral |
| Trip | SCHEDULED→neutral · RUNNING→info · COMPLETED→success · CANCELLED→danger |
| Incident | REPORTED→warning · ACKNOWLEDGED→info · IN_PROGRESS→info · RESOLVED→success · CLOSED→neutral |
| Pass | ACTIVE→success · EXPIRING→warning · EXPIRED→danger · SUSPENDED→danger · PENDING→pending |
| Request | SUBMITTED→pending · APPROVED→success · REJECTED→danger · WITHDRAWN→neutral |

### C-11 · Badge & Counter
**Purpose** — Unread counts, queue depths, notification indicators.
**Rules** — Caps display at `99+`. A zero count renders nothing, never "0". Announces changes
to screen readers via a live region.

### C-12 · Avatar
**Variants** — `image` · `initials` · `role-icon`. Sizes xs–xl.
**Rules** — Falls back to initials, then to a role icon. Never renders a broken image.

### C-13 · Timeline
**Purpose** — Chronological event history: trip progress, incident lifecycle, audit trail,
maintenance ticket.
**Anatomy** — Ordered entries with timestamp, actor, action, optional detail payload and
before/after diff.
**Variants** — `vertical` (default) · `compact` · `stop-progress` (route stops with
planned/actual) · `diff` (audit, with before/after columns).
**Rules**
- Newest first for history; running order for stop progress
- Every entry names its actor, including the system — "System (auto-close)" is a valid actor
- Timestamps show relative time with absolute on hover/press, and are timezone-labelled
- Long timelines paginate rather than truncate silently

### C-14 · Capacity Indicator
**Purpose** — Occupancy against capacity, used at assignment, on trips, and at boarding.
**Anatomy** — Used/total figure · proportional bar · threshold state.
**Variants** — `bar` · `compact` (text only) · `boarding` (large, driver app).
**Rules**
- Thresholds are configuration, not hard-coded: comfortable / filling / near-capacity / full
- At capacity the component changes appearance **before** the action is attempted, so a driver
  knows before the student reaches the step
- Over-capacity (only reachable via an audited override) renders as a distinct alarm state,
  never merely "100%"

### C-15 · KPI Tile
**Purpose** — Dashboard metrics.
**Anatomy** — Label · value · unit · trend (direction + magnitude + comparison period) ·
sparkline (optional) · drill-through target.
**Rules** — Every tile is clickable and lands on the filtered list that produced the number.
A tile without a drill-through is a dead end and is not permitted. Trend requires an explicit
comparison window ("vs. last week"), never a bare arrow. Loading shows a skeleton; unavailable
shows "—" with a reason on hover, never `0`.

### C-16 · Chart
**Variants** — `line` (time series) · `bar` (comparison) · `stacked-bar` (composition) ·
`donut` (share, ≤6 segments) · `heatmap` (occupancy by hour × day).
**Rules**
- Accessible table equivalent available for every chart
- Series distinguished by shape/pattern as well as colour
- Axes always labelled with units; y-axis truncation is disclosed
- Empty and insufficient-data states are distinct ("no data" vs "not enough data to trend")
- Colour comes from the categorical palette in [12 §3](12-design-system.md), in order

### C-17 · Map
**Purpose** — Every geographic surface.
**Anatomy** — Tile layer · bus markers · stop markers · route polyline · geofence circles ·
user location · controls · legend.
**Variants** — `fleet` (many buses) · `trip` (one bus, one route) · `editor` (draggable stops,
AD-53) · `static` (preview thumbnail).
**States** — Loading (frame first, markers stream in) · empty ("no buses running") ·
**stale** (marker appearance changes, age displayed) · **provider-failed** (falls back to a
schematic route/stop list with position expressed as "between stop 4 and 5").
**Rules**
- A stale position must be impossible to mistake for a live one. Age is always visible past
  the staleness threshold
- Markers animate between positions; they never teleport
- Clustering above a density threshold
- The provider-failure fallback is mandatory, not optional — tracking must survive a maps
  outage

### C-18 · Route Progress Strip
**Purpose** — Compact stop-by-stop progress, used on trip cards and the driver's active trip.
**Rules** — Shows served / current / pending / **skipped** distinctly. A skipped stop is
never rendered as merely "not yet reached".

---

## 3. Input & forms

### C-19 · Text Field
**Variants** — `text` · `email` · `phone` · `number` · `password` · `textarea` · `search`.
**States** — default · focus · filled · disabled · readonly · error · success · loading
(async validation).
**Rules** — Label always visible (never placeholder-as-label). Helper text reserves its space
so the layout does not shift when an error appears. Error text replaces helper text and is
announced. Character counter appears at 80% of the limit.

### C-20 · Select & Entity Picker
**Purpose** — Choosing a value, or choosing a record (bus, driver, route, stop, student).
**Variants** — `select` (static options) · `combobox` (searchable) · `entity-picker`
(server-searched records) · `multi`.
**Rules**
- The entity picker shows **only eligible candidates**, and shows ineligible ones greyed with
  the reason ("Licence expired", "Already assigned", "In maintenance") rather than hiding them.
  A driver who cannot find their bus in a list assumes the system is broken
- Async search is debounced, minimum 2 characters, with a loading state
- Recently used and suggested options surface first
- Selected entity renders as a chip with a link to its detail screen

### C-21 · Date & Time Input
**Variants** — `date` · `time` · `datetime` · `date-range` · `recurring` (day-of-week +
frequency).
**Rules** — Institution-local timezone, labelled where ambiguity is possible. Presets for
ranges (today, this week, this term). Invalid ranges are prevented at input, not rejected at
submit. Past dates disabled where the domain forbids them.

### C-22 · Toggle, Checkbox, Radio
**Rules** — A toggle applies immediately and shows an optimistic state with rollback on
failure; a checkbox is part of a form and applies on submit. Never mix the two metaphors on
one screen. Radio groups always have a default or an explicit "none".

### C-23 · File Upload
**Purpose** — Incident photographs, documents, imports, receipts.
**Anatomy** — Drop zone · file list · per-file progress · preview · remove.
**Rules** — Type and size stated before selection, validated after. Image previews are
generated client-side. Upload is resumable where the file is large. On mobile, camera capture
is a first-class option, not buried behind a file browser. Offline uploads queue with their
parent action (driver app).

### C-24 · Form Layout
**Anatomy** — Sections · fields · helper text · error summary · action bar.
**Rules** — Action bar is sticky on long forms. Primary action right (web), full-width
(mobile). Error summary appears at the top when the form exceeds one viewport, with anchors
to each field. Unsaved-changes protection on every navigation path including browser back.
Submit is disabled while in flight and cannot double-fire.

### C-25 · Search Bar
**Variants** — `global` (top bar, cross-entity) · `scoped` (within a list) · `map` (place lookup).
**Rules** — Debounced. Input is treated as **literal text** — wildcard characters carry no
special meaning. Global results are grouped by entity type and keyboard-navigable. Recent
searches are offered. Clearing is always one action.

### C-26 · Filter Bar
**Anatomy** — Filter controls · active-filter chips · result count · clear-all.
**Rules** — Active filters are always visible as removable chips — a user must never wonder
why a list looks short. Result count updates with the filters. State persists per screen for
the session and is held in the URL on web.

### C-27 · Bulk Action Bar
**Purpose** — Appears when rows are selected.
**Anatomy** — Selection count · "select all matching" · actions · clear.
**Rules** — States the exact count before any action. Destructive bulk actions route through
C-31 with a typed confirmation. Results are reported per item (C-32), never as a bare success.

---

## 4. Feedback

### C-28 · Toast
**Variants** — `success` · `info` · `warning` · `error`.
**Rules** — Non-blocking, auto-dismiss 4s (success/info) or manual (warning/error). Carries an
undo action where the operation is reversible. Never the sole channel for a critical message.
Stacks to a maximum of 3; older collapse. Announced politely to screen readers.

### C-29 · Inline Alert / Banner
**Purpose** — Persistent contextual messaging attached to a region or screen.
**Variants** — `info` · `warning` · `danger` · `degraded` (dependency failure) ·
`offline` · `impersonation` · `sos`.
**Rules** — `sos` and `impersonation` are rendered by the app shell (C-01) and cannot be
dismissed by a screen. `degraded` states the functional consequence, not merely that a service
is down: "ETAs are estimates — the maps provider is unavailable."

### C-30 · Empty State
**Anatomy** — Illustration or icon · what is empty · why it might be · the single most useful
next action.
**Variants** — `no-data-yet` · `no-results` (filtered) · `not-applicable` · `no-permission` ·
`awaiting-setup`.
**Rules**
- `no-results` must be visually distinct from `no-data-yet` and must show the active filters
  with a one-tap clear
- Never a dead end: if the user cannot create the missing thing, the empty state says who can
- `awaiting-setup` is the most common state a new student sees ("You'll see your bus here once
  transport is assigned") and must not read as an error

### C-31 · Confirmation Dialog
**Variants** — `simple` (reversible) · `typed` (destructive — requires typing the entity name)
· `reasoned` (requires a reason) · `impact` (shows what will change and who will be notified).
**Rules**
- The variant is determined by the action class in
  [08 §1](08-functionality.md#1-action-taxonomy), not chosen per screen
- `impact` states the recipient count before any notifying action
- The confirming button names the action ("Retire bus"), never "OK"
- Escape and backdrop dismiss only for `simple`; destructive variants require an explicit cancel

### C-32 · Bulk Result Report
**Purpose** — Outcome of a bulk or import operation.
**Anatomy** — Totals (succeeded / failed / skipped) · per-item rows with reasons · retry-failed
action · downloadable report.
**Rules** — Partial success is a normal outcome, never presented as failure. Every failed item
names its reason with an `ERR-xxx` code from [14](14-error-catalogue.md).

### C-33 · Skeleton Loader
**Rules** — Matches the shape and dimensions of the content it replaces, so nothing shifts when
data lands. Used for first load only; refreshes keep existing content visible.

### C-34 · Progress Indicator
**Variants** — `spinner` (indeterminate, short) · `bar` (determinate) · `steps` (wizard) ·
`background-job` (with a cancel affordance).
**Rules** — Beyond 3 seconds, add explanatory text. Beyond 10 seconds, offer cancellation and
state what cancelling does. Background jobs report completion by notification, not by holding
the screen.

### C-35 · Offline Indicator
**Purpose** — Driver app primarily; present on all mobile clients.
**Anatomy** — Connection state · queued-action count · last-sync time · manual sync.
**Rules** — On the driver app, offline is a **normal operating mode**, styled as informational
rather than as an error. It is prominent but not alarming. Queue depth is always visible.

---

## 5. Navigation

### C-36 · Sidebar Navigation
**Rules** — Grouped by module per [10 §2](10-system-map.md). Active item and ancestor group
both indicated. Collapsible with icon-only mode. Badge counts on items with pending work
(alerts, requests, maintenance queue).

### C-37 · Bottom Tab Bar
**Rules** — Four tabs maximum. Icon plus label always — never icon-only. Badge on notification
tab. The default landing tab is context-dependent (tracking during service hours, schedule
outside them).

### C-38 · Breadcrumb
**Rules** — Mandatory below depth 1 on web. Every segment is a link. Truncates in the middle,
never at the ends. Returning via breadcrumb restores the parent list's filters and scroll
position.

### C-39 · Pagination
**Variants** — `numbered` (web) · `infinite-scroll` (mobile) · `load-more`.
**Rules** — Always shows the total count. Page size control on web. Infinite scroll has an
explicit end-of-list marker and a pull-to-refresh.

### C-40 · Stepper / Wizard
**Rules** — Progress visible throughout. Back never loses entered data. Drafts save
automatically. Each step validates before advancing; the final step summarises everything
before commit.

---

## 6. Domain-specific composites

These are composed from the primitives above and are specified because they appear on many
screens and must behave identically everywhere.

### C-41 · Trip Card
**Anatomy** — Route identity · bus · driver · planned/actual times · C-10 status · C-14
capacity · C-18 progress · delay.
**Used by** — AD-03, AD-04, AD-63, DR-01, DR-02, ST-01, ST-08, PA-01, PA-06.

### C-42 · Bus Card
**Anatomy** — Registration · name/model · capacity · C-10 status · assigned driver · document
warning flag.
**Rules** — The document warning flag is part of the card, so an expired certificate is visible
wherever the bus appears, not only on its detail screen.

### C-43 · Person Card
**Variants** — `student` · `driver` · `parent` · `staff`.
**Rules** — Fields shown are role-filtered at the component level; a support agent's student
card renders fewer fields than an operations controller's, from the same component.

### C-44 · Stop Row
**Anatomy** — Sequence number · name · type (pickup/dropoff/both) · scheduled time · assigned
student count · geofence radius.
**Used by** — AD-53, AD-54, DR-05, DR-06, ST-04, ST-05.

### C-45 · Alert Row
**Anatomy** — Severity · type · subject entity · age · assignee · acknowledge action.
**Rules** — Critical severity sorts to the top regardless of the chosen sort order and cannot
be snoozed from the row.

### C-46 · Boarding Control
**Purpose** — The `+1` / `−1` passenger counter. The single most-used control in the product.
**Rules**
- The two largest touch targets on the driver's active-trip screen
- Refuses at capacity with an immediate, unmissable message and the "left behind" path
- Refuses below zero
- Works offline; every press queues with an idempotency key
- Haptic feedback on every press, because the driver is not looking at the screen
- Debounced against double-fire but **never** rate-limited to the point of dropping a genuine
  rapid sequence — students board faster than a UI designer expects

### C-47 · SOS Control
**Purpose** — Emergency trigger.
**Rules** — Present on every driver screen via the shell. Press-and-hold to arm, preventing
pocket activation. Works with no connectivity by falling back to a direct call and SMS. Never
hidden behind a menu. Cancellable within a grace window, but the event is never erased.

### C-48 · Audit Panel
**Purpose** — The who/what/when for any record.
**Anatomy** — C-13 timeline in `diff` variant.
**Rules** — Read-only, always. Present on every detail screen. Shows the system as an actor
where the system acted.

---

## 7. Component coverage check

| Screen family | Components consumed |
|---|---|
| Any list screen | C-01, C-02, C-07/C-08, C-25, C-26, C-27, C-30, C-33, C-39 |
| Any detail screen | C-01, C-02, C-03, C-04, C-09, C-10, C-13, C-48 |
| Any form | C-19..C-24, C-28, C-31 |
| Live operations | C-15, C-17, C-41, C-45, C-29 |
| Driver active trip | C-01(`mobile-focus`), C-14, C-18, C-35, C-44, C-46, C-47 |
| Reports | C-15, C-16, C-21, C-26 |
| Import/export | C-23, C-32, C-34, C-40 |

**Governance rule.** A pull request that introduces a bespoke UI element without a
corresponding entry in this document is rejected at review — see the checklist in
[15 §12](15-developer-handbook.md).
