# Interaction specifications

Restrained on purpose. An operations console is read all day by people who are
not looking at it — they glance. Anything that moves steals a glance.

## Navigation

- Sidebar item → route change, content replaced, scroll to top, focus to `<h1>`
- Sections the access level cannot reach are **absent**, not disabled
- Active item is marked by weight, a left rule **and** `aria-current` — never
  colour alone
- Browser back always works. Every filter, tab, page and open drawer is in the
  URL, so back closes a drawer rather than leaving the screen

## Tables

| Interaction | Behaviour |
|---|---|
| Row click | Opens the drawer or detail. The whole row is the target |
| Action menu | Stops propagation. Never opens the row underneath |
| Sort | Header toggles asc → desc → none. `aria-sort` updated |
| Select | Only where a bulk action exists. The MVP has none, so no checkboxes |
| Keyboard | Rows are focusable; Enter opens; Escape returns focus to the row |
| Hover | Background change only. No row expansion, no delayed popover |

Sorting is **client-side over the page in hand** and is labelled as such where
a total exists that exceeds the page. An interface that sorts 20 of 142 rows
and calls it "sorted by severity" is lying.

## Filters

- Applied on change, not on a submit button — except free text, which is
  debounced 300 ms
- Every filter is a URL query key with the same name the API uses
- The active count and "Clear all" are always visible when any filter is set
- Filters are disabled, not hidden, while offline

## Drawers

- Open: 200 ms slide from the right. Focus moves to the drawer heading
- Focus is trapped while open; Escape closes; focus returns to the row
- Only one drawer at a time. Opening a second **replaces** the first
- The page behind stays interactive and does not scroll-lock unless the
  viewport is under 1280, where the drawer is modal

## Confirmation

Every destructive or outward-facing action confirms first. The dialog:

1. Names the thing — "Cancel TR-014, Velangi → Aditya University?"
2. States the consequence **outside the panel** — "Every student on this route
   will be told the bus is not coming."
3. Puts the destructive action on the right, in `critical`, and focuses Cancel

Never "Are you sure?" alone. The question is not whether they are sure, it is
whether they know what happens next.

## Mutation feedback

```text
click ─▶ button shows a spinner, stays the same width
      ─▶ success  toast, 4s, and the row updates in place
      ─▶ refused  toast that does NOT auto-dismiss, carrying the server's words
      ─▶ failed   toast with Retry, and the row is marked unknown, not failed
```

The button must not change width while submitting — a control that resizes
under the cursor gets double-clicked.

**Refused and failed are different.** Refused means the server considered it and
said no; the message is the server's and stays until dismissed. Failed means
nobody knows whether it landed, so the panel never says "not saved" — it says
the result is unknown and offers a refresh.

## Map

| Interaction | Behaviour |
|---|---|
| Click marker | Selects the bus, opens D1, raises the marker |
| Click empty map | Deselects, closes D1 |
| Click list row | Same as clicking the marker; the map pans, ≤ 300 ms |
| Fit all | Fits the bounds of tracked buses |
| Zoom / pan | Standard. **User pan cancels auto-follow for that session** |

Auto-centring never happens on a poll. If an operator has panned to look at a
junction, the next refresh must not drag them back.

Markers do not animate between positions. A bus that jumps 200 m every 30 s is
telling the truth about the data; a smooth 30-second tween invents a path
nobody observed.

## Polling and staleness

- Every polled screen shows its interval and last-updated time
- Paused on tab hide; one immediate refresh on show
- Three consecutive failures → the offline banner, and polling stops
- Stale data stays on screen, marked. It is never blanked — an operator with
  five-minute-old positions is better off than one with none, as long as they
  know which they have

## Keyboard

| Key | Where | Does |
|---|---|---|
| `/` | Any screen with search | Focus search |
| `Escape` | Drawer / dialog / menu | Close the topmost |
| `Enter` | Focused row | Open |
| `Tab` | Everywhere | Skip-to-content first; logical order |
| `?` | — | Not implemented. No shortcut cheatsheet in the MVP |

Full keyboard operation of tables, filters and dialogs is required. Map
interaction is not keyboard-complete in the MVP — every piece of information on
the map is also in the list beside it, which is.

## Accessibility floor

- Contrast ≥ 4.5:1 for body text, ≥ 3:1 for large text and UI boundaries, in
  both themes. The paired foregrounds in `07` exist for this
- Interactive targets ≥ 32 × 32; row actions 36 × 36
- Visible focus ring on everything focusable, never removed
- Status is never colour alone — always a word or an icon too
- Live regions: the incident badge and the offline banner announce politely.
  Nothing else announces, or a screen reader becomes unusable during a poll

## What is deliberately not built

| Not built | Why |
|---|---|
| Drag and drop | No reorderable data. Every change is an audited transition |
| Inline cell editing | Hides the fact that each mutation is a distinct, gated, audited call |
| Toast stacking | One at a time. A queue of five toasts is read by nobody |
| Auto-refresh on reports | Reports are a point in time and must not move while being read |
| Sound or desktop notifications | An SOS is at the top of Attention Required and on the badge. Startling the operator adds nothing |
| Optimistic mutation UI | The driver app is optimistic because a bus cannot wait. An office can wait 400 ms for the truth |
