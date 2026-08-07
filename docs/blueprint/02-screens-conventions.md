# Phase 2 — Screen Conventions

Stated once, in full, so that the ~190 individual screen specifications document what is
**unique** to each screen rather than repeating this page. A screen entry that is silent on
any behaviour below inherits the behaviour defined here.

---

## 1. Universal screen states

Every screen that fetches or submits data implements all five.

### 1.1 Loading

| Case | Treatment |
|---|---|
| First load of a list or detail | Skeleton placeholders matching the final layout — never a bare spinner, never a layout that jumps when data lands |
| Refresh of already-visible data | Existing content stays, with a subtle progress indicator. Never blank out data the user is reading |
| Submitting a form | Submit button enters a disabled loading state; the form stays visible and readable; no full-screen block |
| Slow response (> 3s) | Explanatory message appears: "Still working…" |
| Very slow (> 10s) | Offer to cancel, and explain what happens if they do |
| Background refresh (live data) | Silent. Only a "last updated" timestamp changes |

### 1.2 Empty

Empty is not an error, and an empty screen must never be a dead end. Every empty state has
three parts: **what is empty**, **why it might be**, and **the single most useful next action**.

| Case | Treatment |
|---|---|
| No records exist yet | Explain the feature's purpose plus the primary create action, if the user has permission to create |
| No records exist and the user cannot create | Explain who can, and how to ask them |
| Filters or search returned nothing | Distinguish clearly from "nothing exists". Show the active filters and a one-tap clear |
| Data exists but not for the selected date | State the date explicitly and offer to jump to the nearest date that has data |
| Feature not yet available to this user | Explain the prerequisite (e.g. "You will see your bus here once transport is assigned") |

### 1.3 Error

| Case | Treatment |
|---|---|
| Validation failure (422) | Inline, next to each offending field, with the field marked and focus moved to the first error. A summary at the top when the form is long enough that errors may be off-screen |
| Not authenticated (401) | Redirect to sign-in, preserving the intended destination so the user lands where they meant to go |
| Not permitted (403) | Explain that access is denied and who to contact. Never reveal whether the record exists |
| Not found (404) | "This no longer exists or was removed", plus a route back to the parent list |
| Conflict (409) | Explain the *state* that blocks the action in business language, not a status code. "This bus is on an active trip" — not "invalid transition" |
| Rate limited (429) | Explain the limit and when they can retry |
| Server error (500) | Apologise, show a support reference identifier that matches the server log, offer retry. Never show a stack trace |
| Network offline | Persistent banner. On the driver app, switch to offline mode rather than showing an error |
| Partial failure in a bulk action | Show exactly which items succeeded and which failed, with per-item reasons and a retry-failed-only action |

### 1.4 Success

| Case | Treatment |
|---|---|
| Create | Toast confirmation, then navigate to the new record's detail screen |
| Update in place | Inline confirmation, form stays, changed values reflected |
| Delete / retire | Toast with an undo affordance where the action is reversible; a firm confirmation dialog beforehand where it is not |
| Long-running action | Immediate acknowledgement that it has started, plus a way to watch progress. Never a frozen screen |
| Action with downstream effect | State the effect: "Trip cancelled. 43 passengers and 38 guardians notified." |

### 1.5 Offline (mobile clients)

| Case | Treatment |
|---|---|
| Read while offline | Serve the last cached view with a visible staleness indicator and its timestamp |
| Write while offline (driver) | Accept, queue locally, show a pending badge and a queue depth. This is the normal path, not an error |
| Write while offline (student / parent) | Reject with an explanation; these actions are not safety-critical and must not be silently queued |
| Reconnect | Sync automatically, show what synced, and surface any conflicts for resolution |

---

## 2. Lists, search, filter, sort, pagination

### 2.1 Default list behaviour

- Page size 25 on web, 20 on mobile. User-adjustable on web to 50 or 100. Server-capped at 100
- Sort defaults to most-recent-first, except where a natural order exists (route stops by
  sequence, schedules by day then departure time, students by name)
- Every column header on web is sortable unless the underlying value is not orderable
- Filter state, sort and page are held in the URL on web, so a view can be shared as a link
- Filters persist for the session per screen; a clearly visible "clear all" resets them
- Active filters are shown as removable chips above the list, with a result count
- Row click opens detail. Row actions live in an overflow menu, never as a row of buttons
- Bulk selection with a select-all-matching-filter option, and an explicit count of what is
  selected before any bulk action runs

### 2.2 Search

- Global search (top bar, web): searches across students, drivers, buses, routes and trips by
  name, identifier, registration number or phone. Grouped results by entity type, keyboard
  navigable
- Screen-level search: scoped to the current list, debounced, matched against that entity's
  meaningful fields, with matched terms highlighted
- Search input is treated as literal text — wildcard characters carry no special meaning
- No results shows the query, the scope searched, and suggestions

### 2.3 Pagination

- Server-side, always. No screen ever loads an unbounded set
- Web: numbered pagination with total count and page-size control
- Mobile: infinite scroll with an explicit end-of-list marker and a pull-to-refresh
- Live-updating lists (active trips, alerts) do not paginate; they are bounded by nature and
  refresh in place

### 2.4 Export

- Any list can be exported as CSV or XLSX, honouring the active filters
- Exports over a threshold are generated asynchronously and delivered by notification
- Every export containing personal data is audit-logged with the requester and row count
- Export respects field-level permissions: a support agent's export omits the columns their
  role cannot see on screen

---

## 3. Forms

- Required fields are marked; optional fields are marked when most of the form is required
- Validation on blur for format, on submit for everything, and server-side always
- Server validation is authoritative; client validation exists only to be fast
- Destructive or irreversible actions require a typed confirmation of the entity's name, not
  a bare OK
- Unsaved changes prompt on navigate-away, close, and browser back
- Multi-step wizards show progress, allow going back without loss, and save a draft
- Dates are entered in institution-local time and labelled with the timezone where ambiguity
  is possible
- Money is entered and displayed with an explicit currency
- Phone numbers are validated for format and normalised for storage
- A form is never submitted twice by a double click

---

## 4. Permissions in the interface

- **The interface hides what a user cannot do, and the server refuses it regardless.** UI
  hiding is a courtesy; the server check is the control. Both are mandatory
- An action the user could perform but not right now (wrong state, missing prerequisite) is
  shown **disabled with the reason**, not hidden. Hiding it makes the system feel broken
- An action the user's role can never perform is hidden entirely
- Navigation items a role cannot reach are absent from the sidebar
- A directly-entered URL to a forbidden screen shows the permission-denied state, not a blank

---

## 5. Real-time behaviour

| Element | Update mechanism |
|---|---|
| Live map bus positions | Streamed; animated between points rather than teleporting |
| ETA | Recalculated on each position update, displayed with a confidence indication |
| Trip status | Streamed; the list re-sorts without losing the user's scroll position |
| Alerts and notifications | Streamed; badge count updates; a toast for critical classes only |
| Everything else | Polled on a sensible interval, or refreshed on user action |
| Connection lost | The live indicator turns stale, timestamps show age, and the client reconnects with backoff |

---

## 6. Accessibility and localisation

- Full keyboard navigation on web; visible focus; logical tab order; skip-to-content
- Screen-reader labels on every control; live regions for status changes
- Colour is never the sole carrier of meaning — status uses shape or text as well
- Minimum 4.5:1 contrast for text; 44×44pt minimum touch targets on mobile
- The driver app supports high-contrast and large-text modes, because it is used in daylight
  glare, and one-handed operation, because the other hand is on a door handle
- All user-facing text is externalised for translation; the student and parent apps ship in
  the institution's local languages
- Dates, numbers and currency follow the user's locale

---

## 7. Screen entry template

Every screen in documents 03–07 uses this structure:

```
### <ID> · <Screen Name>                                    [P0|P1|P2] [FR-xx] [NEW]
**Purpose** — one sentence.
**Access** — roles, plus any record-level condition.
**Entry** — how the user arrives.
**Exit** — where they can go.
**Actions** — everything they can do.
**Validations** — what is checked.
**States** — only what differs from the conventions above.
**List behaviour** — search, filter, sort, pagination, where applicable.
**Workflows** — links to Phase 4 flows.
```

Screen identifiers are stable and are used for cross-referencing throughout the blueprint:

| Prefix | Surface |
|---|---|
| `SH-` | Shared / authentication |
| `AD-` | Admin & staff console |
| `DR-` | Driver app |
| `ST-` | Student app |
| `PA-` | Parent app |
