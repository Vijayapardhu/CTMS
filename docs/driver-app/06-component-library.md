# Driver App — Phase 6: Component Library

**Derived from:** [05 — Wireframes](05-wireframes.md)
**Rule:** every component below exists because at least two wireframes needed it. Nothing is here speculatively.

---

## Sizing floor

Non-negotiable across every component. These come from the driver's context, not from a style guide.

| Constraint | Value | Why |
|---|---|---|
| Minimum touch target | **48 × 48 dp** | Material floor; gloves |
| Primary action height | **64 dp** | Start trip, Send alert — found without looking |
| Counter buttons | **96 dp square** | Used hundreds of times per shift, one-handed |
| Minimum body text | **16 sp** | Legible in direct sun at arm's length |
| Minimum spacing between targets | **8 dp** | Prevents mis-taps on a moving vehicle |
| Maximum line length | **60 characters** | Beyond that it stops being glanceable |

---

## 1 · `StatusChip`

**Purpose** one word plus a mark that says what state something is in.
**Used by** trip card, stop card, incident card, inspection item, queue entry.

| Variant | Applies to |
|---|---|
| `ready` · `running` · `blocked` · `closed` | trip |
| `pending` · `arrived` · `departed` · `skipped` | stop |
| `open` · `acknowledged` · `resolved` | incident |
| `queued` · `syncing` · `failed` | queue |

```
Properties   label, variant, icon?, dense
States       default, dense (in lists)
A11y         semantic label reads "{label}, {variant}" — never colour alone
```

**Every chip carries an icon as well as colour.** A driver with deuteranopia must distinguish `running` from `blocked` without seeing hue. This is the single most common accessibility failure in operational software.

---

## 2 · `PrimaryButton` / `SecondaryButton` / `DangerButton`

**Used by** every screen with an action.

```
Properties   label, onPressed, loading, enabled, icon?, fullWidth
States       enabled · pressed · disabled · loading
Heights      standard 56dp · prominent 64dp (start trip, submit, send alert)
Loading      spinner replaces label; width does not change (no layout jump)
Disabled     must always be accompanied by visible reason text nearby
```

**A disabled button with no explanation is forbidden.** Where `Review (11 remaining)` is disabled, the count *is* the explanation and lives in the label.

`DangerButton` is used for exactly three things: submitting a failing inspection, choosing "No" on *can the bus continue*, and sending an SOS. Nothing else earns it.

---

## 3 · `DualActionSelector`

**Purpose** a binary choice where both options are real and neither is a default.
**Used by** inspection Pass/Fail, incident Can-continue Yes/No.

```
Properties   leftLabel, rightLabel, value (nullable), onChanged, danger?
States       unselected (both neutral) · left · right · disabled
Sizing       each option ≥ 56dp tall, equal width
```

**`value` starts null.** No pre-selection. On the `vehicle_can_continue` control a pre-selected "Yes" would let a driver submit a grounded-bus report without ever making the choice — and a pre-selected "No" would ground buses that are fine.

---

## 4 · `CounterButton`

**Purpose** board and alight.
**Used by** running trip, stop details.

```
Properties   direction (board|alight), count, capacity, enabled, onTap
Size         96 × 96 dp minimum
Feedback     haptic on every tap · count animates by increment, never crossfade
Disabled     board disables at capacity, with the count visible as the reason
Offline      identical behaviour; a pending mark appears on the counter, not the button
```

The button never shows a spinner. The tap is optimistic and instant — a driver counting twelve students cannot wait for twelve round trips.

---

## 5 · `BigNumberDisplay`

**Purpose** the one number that matters, readable at a glance from the driver's seat.
**Used by** running trip (`23 / 40`), tablet pane, trip summary.

```
Properties   value, total?, label, pending?, warning?
Sizing       value at display scale (57sp+); label at body
Pending      "· 3 pending" appended in the label line, never in the number
Warning      at ≥ 95% capacity, the number takes the warning treatment
```

The pending count **never** merges into the main number. `26 on board · 3 pending` is honest; `29 on board` is a lie the server has not agreed to.

---

## 6 · `ReasonList`

**Purpose** render `reasons[]` from the API, grouped by whether the driver can act.
**Used by** vehicle blocked, start refused, queue rejections.

```
Properties   actionable: List<String>, blocking: List<String>
Layout       actionable group first, with a heading; blocking group second, quieter
Empty        renders nothing (not an empty state)
```

This component exists because the backend deliberately returns **every** blocking reason at once. Rendering only the first, or rendering them undifferentiated, both defeat that design.

---

## 7 · `TripCard`

**Used by** trip root (all states), history, summary.

```
Properties   trip, readiness?, compact
States       none · blocked · ready · running · closed · waiting
Content      registration (largest), route, window, StatusChip
Tap          → trip details, except in `running` where the card is not tappable
```

In `running` the card is the screen, not a card. This is one widget with a state parameter rather than five widgets, because the trip is one object.

---

## 8 · `StopCard`

**Used by** running trip (next stop), stop list, map sheet, stop details header.

```
Properties   stop, state, etaMinutes?, distanceKm?, expectedCount?, isEstimate
States       pending · approaching · arrived · departed · skipped
Estimate     when isEstimate, the ETA is prefixed "~" and labelled "estimated"
```

`isEstimate` comes from the routing provider's `is_estimate`. A schedule-derived guess must never render identically to a live traffic-aware ETA.

---

## 9 · `ChecklistItemTile`

**Used by** inspection checklist.

```
Properties   item, verdict, isSafetyCritical, notes, evidenceId, onChanged
States       unanswered · passed · failed · failed-incomplete (needs note/photo)
Expansion    expands on fail to reveal notes and, if critical, the evidence action
Marker       safety-critical items carry a persistent marker even when passing
```

`failed-incomplete` is a real state and blocks review. It is what stops a driver failing the brakes and walking away without evidence.

---

## 10 · `EvidenceCard`

**Used by** checklist tile, incident form, incident detail.

```
Properties   evidenceId?, localPath?, state, required, onCapture, onRetake
States       empty · capturing · preview · uploading · uploaded · queued · rejected
Rejected     shows the server's reason ("Photographs only", "Too large")
Queued       thumbnail with an offline mark — the photo exists, the id does not yet
```

---

## 11 · `ConsequencePanel`

**Purpose** state what is about to happen, before it happens.
**Used by** inspection review, incident can-continue, skip stop, cancel SOS.

```
Properties   severity (info|warning|danger), title, body
Never        used after the fact — that is a Snackbar's job
```

Exists because three separate flows need to warn a driver *before* an irreversible act. Grounding a bus, stranding waiting students, withdrawing an emergency alert.

---

## 12 · `HoldToActivate`

**Purpose** an action too consequential for a tap, too urgent for a dialog chain.
**Used by** SOS only.

```
Properties   label, holdDuration (1500ms), onActivated
Feedback     progress ring fills · haptic at start, mid, and completion
Release      early release cancels silently, no error
A11y         screen-reader users get an equivalent double-confirm button —
             a timed hold is not operable with TalkBack
```

The accessibility alternative is not optional. A hold gesture is invisible to a screen reader and impossible with switch access.

---

## 13 · `GpsStatusPill`

**Used by** running trip header, map overlay.

```
Properties   state (acquiring|live|buffering|noSignal), bufferedCount
States       live (steady) · acquiring (slow pulse) · buffering (count shown) · noSignal
Tap          → offline queue
Never        blocks, never a dialog, never a toast
```

---

## 14 · `PersistentBanner`

**Used by** offline, syncing, sync-failed, replacement dispatched.

```
Properties   severity, message, action?, dismissible (default false)
Placement    directly under the app bar; pushes content down, never overlays
Offline      not dismissible — the condition is still true if dismissed
```

A banner, not a snackbar. A snackbar disappears; the offline condition does not.

---

## 15 · `SyncQueueTile`

**Used by** offline queue.

```
Properties   action, timestamp, state, failureReason?
States       waiting · syncing · failed
Failed       shows the server's message verbatim, plus what the action was
```

---

## 16 · `EmptyState`

**Used by** no trip, no notifications, no history, empty manifest.

```
Properties   title, body?, action?, tone (neutral|informative)
Never        red, never a warning icon, never the word "error"
```

An empty day is not a failure. This component's tone rule is the whole reason it exists rather than reusing an error view.

---

## 17 · `SkeletonLoader`

**Used by** trip card, stop list, notification list, manifest.

```
Properties   shape (card|list|line), count
Shimmer      1200ms, respects reduce-motion (falls back to a static block)
Rule         used only where the layout is known in advance — otherwise a spinner
```

---

## 18 · `AppBar` / `BottomNav`

```
AppBar       title, progress? ("3/14" lives here), leading, actions ≤ 2
BottomNav    4 destinations, labels always visible, badge on Alerts
             Never hidden on scroll — a driver must not have to hunt for it
```

---

## 19 · `ConfirmSheet`

**Used by** start trip, end trip, skip stop, left behind, incident type, duty status.

```
Properties   title, body?, confirmLabel, danger?, child?, onConfirm
Dismiss      swipe down or tap outside — always allowed except SOS active
Sizing       confirm action 64dp when the act is irreversible
```

---

## 20 · `PermissionGate`

**Used by** location, camera, notifications.

```
Properties   permission, rationale, onGranted, blocking
Blocking     location for a running trip, camera for a critical failure
Non-blocking notifications — degraded, app continues
Content      states plainly what cannot happen without it, then offers Settings
```

---

## Component → screen matrix

| Component | Screens |
|---|---|
| `StatusChip` | R1, P5, P7, P8, P13, P19, P20, M3 |
| `PrimaryButton` | almost all |
| `DangerButton` | P10, P18, S1 |
| `DualActionSelector` | P9, P18 |
| `CounterButton` | R1, P13, P15 |
| `BigNumberDisplay` | R1, P7 |
| `ReasonList` | P6, R1 |
| `TripCard` | R1, P5, P7, P8 |
| `StopCard` | R1, R2, P13, P16 |
| `ChecklistItemTile` | P9 |
| `EvidenceCard` | P9, P18, P19, M1, M2 |
| `ConsequencePanel` | P10, P18, S4, D2 |
| `HoldToActivate` | C1 |
| `GpsStatusPill` | R1, R2 |
| `PersistentBanner` | all (C2, C3), P21 |
| `SyncQueueTile` | M3 |
| `EmptyState` | R1, R3, P8, P14, P20 |
| `SkeletonLoader` | R1, R3, P8, P14 |
| `ConfirmSheet` | S1–S7 |
| `PermissionGate` | M4, E2–E5 |

**Twenty components. Four screens' worth of chrome.** That is the whole surface — which is what tells us the design system needs far fewer tokens than a general-purpose one.

**Next:** Phase 7 — Design system, sized to exactly this list.
