# Component library

Twenty-six components. Each is here because two or more screens need it, or
because it encodes a rule that must not be re-decided per screen.

Every entry states purpose, variants, states, dimensions, interaction,
accessibility and responsive behaviour.

---

## C1 AppShell

Sidebar, top bar, content region, drawer layer, toast layer.
**States:** ready · offline (banner pushes content down, never overlays) ·
session-ending.
**Dimensions:** grid `240px | 1fr`, collapsed `64px | 1fr`.
**Accessibility:** one `<main>`; skip-to-content first in tab order.
**Responsive:** < 1280 collapses the sidebar; < 1024 makes it off-canvas.

## C2 Sidebar

Sections from `02-screen-api-matrix.md`.
**Variants:** expanded · collapsed (icon + tooltip).
**States:** item default · hover · active · **absent** when the access level
cannot reach the screen.
Administration is absent below `SUPER_ADMIN` — not greyed. A permanently
disabled control teaches people the product is broken for them.
**Accessibility:** `<nav>`, `aria-current="page"`.

## C3 TopBar

Page title, global search (post-MVP), notification badge, account menu.
**States:** default · scrolled (elevation 1) · offline (badge muted).
**Height:** 56.
The badge is the server's `unread-count`, never a client tally.

## C4 PageHeader

Title, subtitle, breadcrumb, primary action, secondary actions.
**Variants:** with/without filter bar; with/without back.
Actions the level cannot perform are **absent**; actions blocked by state are
**disabled with a reason on hover**.

## C5 MetricCard

One number and its meaning. Dashboard only.
**Variants:** plain · with trend · with severity tint.
**States:** loading (skeleton at final height) · loaded · **failed (retry
inside the card)** · empty (`—`, never `0` when unknown).
**Dimensions:** 240 × 96 min.
The distinction between "zero incidents" and "we could not ask" is the whole
point of this component. `0` and `—` are different.

## C6 StatusChip

A status word plus its icon in its semantic colour.
**Variants:** solid · subtle · outline. **Sizes:** sm 20, md 24.
**Rule:** never colour alone — every chip carries the icon from `08`.
**Accessibility:** the text is the label; the icon is `aria-hidden`.

## C7 SeverityBadge

Incident severity. Four values, `emergency` for `CRITICAL`.
**Variants:** dot (in dense tables) · badge (in cards).
Sorts by severity ordinal, not alphabetically.

## C8 DataTable

The panel's centre of gravity.
**Variants:** standard · compact · selectable.
**States:** loading (5 skeleton rows) · loaded · empty · error · refreshing
(existing rows stay, header shows a subtle progress line).
**Interaction:** whole row opens the detail drawer; the action menu stops
propagation; sortable headers toggle asc/desc/none.
**Columns:** each declares a priority; low-priority columns drop below 1280
rather than scrolling horizontally.
**Accessibility:** real `<table>`, `<th scope="col">`, `aria-sort`; rows are
focusable and open on Enter.
**Never** horizontally scrolls when a drawer could carry the detail.

## C9 FilterBar

Filters for a table, reflected in the URL query so a filtered view is a link.
**Variants:** inline (≤ 4 filters) · with overflow "More filters".
**States:** clean · active (count badge, "Clear all") · disabled while offline.
Each filter maps to a **real** query key from `01-api-contract.md`. A filter
with no backend key is not built — see G1-2 for what that costs on A8.

## C10 SearchField

Debounced 300 ms, minimum 2 characters, `aria-label` always.
Only on screens whose endpoint has a `search` key — currently `GET /buses`.
Client-side filtering of a paginated list is a lie and is not offered.

## C11 DateRangePicker

**Variants:** single date · range · presets (Today, Yesterday, Last 7, This
month).
Emits `date` or `from`/`to`, matching the endpoint. Defaults to today
everywhere except reports, which default to this month.
**Timezone:** the device's, rendered as IST for this college. Sent as the
backend expects; stored UTC.

## C12 MapPanel

Google Maps, Live Operations and trip detail.
**States:** loading · ready · **no-key** (a panel saying the map is not
configured, with the rest of the screen still working) · provider-error ·
empty (no running trip).
**Interaction:** select bus · fit route · centre selected · zoom. Nothing else.
A map with fifteen controls is a map nobody reads.
**Responsive:** 60% width beside the trip list at ≥ 1440; full width with the
list as an overlay sheet below 1280.

## C13 BusMarker

**States:** running (primary, live accent ring) · stale (desaturated, no ring)
· incident (emergency, pulsing once on arrival then still) · selected (raised,
labelled) · unknown.
Staleness comes from `is_stale` on the server's response. **Never computed from
a timestamp in the browser.**

## C14 StopMarker

**States:** pending · approaching · arrived · departed · skipped —
`StopProgressState`, one to one.
Sizes down to a dot below zoom 13 so a dense route does not become a smear.

## C15 RoutePolyline

**Variants:** ahead (`mapRoute`) · completed (`mapRouteDone`).
Geometry from `GET /routes/{id}/stops`, cached per route for the session.
**No routing calls from the browser.** The backend owns Google Routes.

## C16 DetailDrawer

Right-side overlay for a row's detail.
**Variants:** standard 480 · wide 640 (evidence).
**States:** opening · loaded · loading · error · closing.
**Interaction:** Escape closes; focus is trapped and restored to the row on
close; the URL updates so a drawer is linkable.
**Never** stacks. Opening a second drawer replaces the first.

## C17 Timeline

Ordered events — incident history, trip stops, maintenance transitions.
**Variants:** compact · detailed. Each entry: time, actor, what changed.
Time is absolute (`14:32`), with relative in the tooltip. "2 hours ago" on an
operations console is a number somebody has to convert back.

## C18 ConfirmationDialog

**Variants:** standard · destructive (critical confirm) · with reason field.
**Required for:** cancel trip, reassign, close incident, cancel incident,
complete maintenance, return to service, publish announcement, withdraw
announcement, deactivate account, subject-access export.
The body names the **consequence outside the panel** — "Every student on this
route will be told the bus is not coming" — not the mechanism.
**Accessibility:** `role="alertdialog"`, focus on the cancel action.

## C19 ActionMenu

Row-level overflow. Items absent by level, disabled with a reason by state.
Destructive items are separated and coloured `critical`.

## C20 EmptyState

Icon, title, one sentence, optional action.
**Never red, never "error", never a warning icon.** A day with no incidents is
the best possible day.

## C21 ErrorState

Icon, what failed, retry.
**Variants:** page · card · inline.
Shows the server's message for 409, a fixed sentence for 403, and a generic one
for 5xx. Never a path, never a stack.

## C22 Skeleton

**Variants:** text · row · card · map.
Matches the final layout's dimensions so nothing shifts. First load only —
a refresh keeps the old data and shows a progress line instead.

## C23 Pagination

Page size 20 · 50 · 100 (the server's cap). Shows "1–20 of 142" from
`pagination`, never a count of rows in hand. Page is in the URL.

## C24 Toast

Mutation feedback. Bottom-right, 4 s, one at a time, dismissible.
**Variants:** success · refused (persistent until dismissed, carries the
server's words) · failure (with retry).
A refusal is **not** a toast that vanishes — the driver needs to read it, and so
does an operator.

## C25 EvidenceViewer

Photographs cited by an incident or inspection.
**States:** loading · loaded · forbidden · unavailable.
Fetched by id from `GET /evidence/{id}`; never guessed from a path.
Zoom, and metadata (captured at, by whom). No download in the MVP — evidence
leaving the system is an export decision nobody has taken.

## C26 AuditEntry

One audit row: actor, action, table, record, before/after, time, IP.
**Variants:** row · expanded diff.
Read-only by construction; there is no write path to the audit log and the
component has no edit affordance to remove.

---

## Deliberately not built

| Component | Why |
|---|---|
| Chart / graph library | The six reports return numbers. Tables answer the demo's questions; charts are Post-MVP |
| Kanban board | Maintenance has five statuses and a table with a status filter reads faster |
| Global command palette | Nice, and not what management is being shown |
| Inline row editing | Every mutation is an audited transition with its own endpoint and confirmation. Editing a cell hides that |
| Notification centre panel | A13 is a screen. A second surface for the same data is two things to keep in step |
