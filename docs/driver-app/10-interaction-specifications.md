# Driver App — Phase 10: Interaction Specifications

**Derived from:** Phases 5–9. Gestures, timings, feedback, accessibility behaviour.

---

## The governing constraint

Every interaction is specified for someone who is **standing, possibly gloved, in a moving or about-to-move vehicle, with people waiting**. That rules out: long-press menus, swipe-to-reveal actions, drag-to-reorder, multi-finger gestures, and anything requiring precision.

The app uses **four** gestures. That is the whole vocabulary.

| Gesture | Where | Never used for |
|---|---|---|
| Tap | everything | — |
| Hold (1.5s) | SOS only | any other action |
| Vertical drag | scroll, sheet dismiss, pull-to-refresh | actions |
| Horizontal swipe | dismiss a notification (Alerts only) | destructive actions |

No swipe-to-delete on anything operational. No long-press anywhere except SOS.

---

## Touch and feedback

### Haptics

| Event | Pattern | Why |
|---|---|---|
| Board / alight tap | `selectionClick` | Confirms the count changed without looking |
| Pass / Fail selection | `selectionClick` | |
| Primary action | `mediumImpact` | Start trip, submit |
| SOS hold — start | `mediumImpact` | Hold registered |
| SOS hold — 50% | `lightImpact` | Progress is happening |
| SOS hold — complete | `heavyImpact` ×2 | Unmistakable |
| Refusal (409/422) | `heavyImpact` | Something was rejected |
| Sync complete | `lightImpact` | Only if the app is foregrounded |
| Geofence arrival | `mediumImpact` + sound | The driver may not be looking |

**Haptics are not decoration here.** A driver boarding twelve students in sequence relies on the tick to know each tap registered, without looking down.

Respect the system haptic setting. If disabled, the visual count animation carries it alone.

### Press states

Every tappable surface: 8% overlay on press, 150ms ripple from the touch point. Buttons scale to 0.98 over 100ms.

`CounterButton` is the exception — **no scale**, because a 96dp target shrinking under a thumb reads as a mis-tap. Overlay and haptic only.

---

## Per-interaction specifications

### SOS hold

The only gesture with real complexity, and the one that must not fail.

```
touch down
  ├─ 0ms      haptic mediumImpact · ring begins filling · label → "Hold…"
  ├─ 750ms    haptic lightImpact  · ring at 50%
  ├─ 1500ms   haptic heavyImpact ×2 · ring complete · sheet S1 opens
  └─ release before 1500ms
              ring collapses over 200ms · no haptic · no message
```

- Ring fill is **linear**. Easing would misrepresent how much longer to hold.
- Early release is silent. A driver who brushed it should feel nothing happened, because nothing did.
- The gesture works with a **wet or gloved finger**: the hit area is 72dp even though the visual is 56dp.
- Movement tolerance ±24dp — a hold on a moving bus drifts.

**Accessibility alternative** — with TalkBack or Switch Access active, `C1` becomes a standard button that opens `S1` directly. Announced as *"Emergency alert, button. Double tap to open confirmation."* A timed hold is not operable with either.

### Counter tap

```
tap
  ├─ 0ms    haptic · overlay
  ├─ 0ms    count increments IMMEDIATELY (optimistic)
  ├─ 180ms  number slides up into place (easeOutBack)
  └─ async  POST; on 409 the number rolls back with a shake and the reason
```

**Rapid tapping must work.** A driver counting eight students taps eight times in three seconds. Each tap queues its own request with its own idempotency key; the UI never debounces the count, only the network layer batches.

Rollback on refusal is visible: the number decrements with a 300ms shake and the message appears. Silently discarding a tap is worse than showing the refusal.

### Pull to refresh

Standard Material. Threshold 80dp, indicator under the app bar. Available on every screen with remote data. Disabled during a blocking submit.

### Sheet dismissal

Drag down past 40% of height, or tap the scrim. `S1` (SOS confirm) also dismisses on back. `P17` while an SOS is active does **not** dismiss — only Withdraw closes it.

### Text entry

- Odometer: numeric keyboard, no decimal, thousands separator inserted as typed
- Notes: multiline, sentence capitalisation, no autocorrect (vehicle terms fight it)
- Search: none in this app — there is nothing large enough to search

Fields never validate on keystroke. Validation fires on blur or submit. A driver typing "45 1" should not be told it is below the minimum.

---

## Motion in context

Durations from Phase 7. What matters is *when* each applies.

| Transition | Motion |
|---|---|
| Tab switch | Fade-through 200ms. No slide — tabs are peers |
| Push (trip → stop) | Shared-axis X, 300ms |
| Modal (evidence) | Shared-axis Z, 250ms |
| Sheet | Slide up 300ms, `easeOutCubic` |
| State change within R1 (`ready` → `running`) | **Crossfade 250ms, no push.** Same destination, new shape |
| Banner appear | Height expand 200ms, pushes content down |
| Counter | 180ms slide, `easeOutBack` |
| Map marker | 1000ms linear interpolation between fixes |
| Map camera | 600ms `easeInOutCubic`, only on recentre or first fix |

**The R1 state change is deliberately a crossfade.** A driver tapping Start should feel the same screen change state, not navigate somewhere new — because they have not: the trip is one object.

### Reduce motion

All durations → 0 except: banner 200ms (its arrival must be noticed), snackbar unchanged, marker snaps rather than interpolates. Pulses become static. Skeleton becomes a flat block.

---

## Map interactions

| Interaction | Behaviour |
|---|---|
| Pan | Free. Disables auto-follow, shows a Recentre button |
| Pinch zoom | 12–19. Below 12 loses stop detail; above 19 adds nothing |
| Recentre | 600ms camera, re-enables follow |
| Tap stop marker | Bottom sheet with `StopCard`; does not navigate |
| Tap bus marker | Nothing. It is not a control |
| Rotate | **Disabled.** A rotated map on a moving vehicle disorients |
| Tilt | **Disabled** |

Auto-follow keeps the bus at 40% from the top so the road ahead is visible — not centred.

**Offline map** — tiles cached along the route at trip start. Uncached area renders a neutral grid with "Map unavailable offline". The route line, stops and bus marker still draw, because those are local data.

---

## Notification behaviour

| App state | Behaviour |
|---|---|
| Foreground | In-app banner 4s, tap to navigate. **No system notification.** |
| Background | System notification, grouped by trip |
| Killed | System notification; tap cold-starts and deep-links after auth |

| Priority | Treatment |
|---|---|
| `CRITICAL` | Heads-up, sound, bypasses Do Not Disturb, cannot be swiped away |
| `STANDARD` | Standard, silent while a trip is running |

Grouping: by `data.trip_id` where present. Badge = `/notifications/unread-count`, refreshed on push and on Alerts open.

**Never notify** for something the driver is currently looking at. If R3 is visible, mark read and skip the banner.

---

## Keyboard and switch access

Full traversal support even though few drivers will use it — supervisors on tablets with keyboards will.

| Key | Action |
|---|---|
| Tab / Shift-Tab | Focus traversal in visual order |
| Enter / Space | Activate focused control |
| Escape | Dismiss sheet or dialog |
| Arrow keys | Move within a `DualActionSelector` |

Focus indicator: 2dp `primary` outline at 2dp offset. Never removed.

Focus order is explicit on every screen, not inferred: title → content → primary action. On `P9`, focus lands on the first unanswered item, not the top.

---

## Screen reader

**Announcements** (live regions):

| Event | Announced |
|---|---|
| Count change | "23 on board" — polite |
| GPS state change | "Position lost, buffering" — polite |
| Sync complete | "All changes synced" — polite |
| SOS queued | "Alert saved, will send when signal returns" — **assertive** |
| Refusal | the server's message — **assertive** |

**Labels**

- `CounterButton` → "Board a passenger. 23 of 40 on board."
- `StatusChip` → "Status: ready"
- `GpsStatusPill` → "GPS live" / "No signal, 12 positions waiting"
- `ChecklistItemTile` → "Brakes, safety critical, not answered" / "…, failed"
- `HoldToActivate` → replaced by a button (see above)

Decorative elements only: the empty-state illustration.

---

## Error presentation

| Kind | Surface | Dwell |
|---|---|---|
| Field validation | Inline under the field | until fixed |
| Action refused (409) | Snackbar with the server's message | 6s (has action) |
| Screen load failure | Inline error card + Retry | persistent |
| Connectivity | `PersistentBanner` | while true |
| Sync conflict | Banner → `M3` | until reviewed |
| Fatal | Full screen | persistent |

**A refusal during a running trip is never a dialog.** Dialogs block, and a driver at a stop with people boarding cannot be blocked. Snackbar with the message, haptic, and the counter rolls back.

### Copy rules

- Say what happened and what to do: *"The bus is full (40/40). This student cannot board."*
- Never expose codes, endpoints, or class names
- **Never paraphrase a 409.** The backend messages are already written for drivers; show them verbatim
- Never "Oops" or "Something went wrong" where a real message exists

---

## Timing summary

| Thing | Value |
|---|---|
| SOS hold | 1500ms |
| GPS sample | 5–10s adaptive (5s moving, 10s stationary) |
| `/live` poll | 10s, foreground + running only |
| Sync retry backoff | 1s, 2s, 4s, 8s, 30s, 60s, then 60s |
| SOS retry backoff | 1s, 2s, 5s, 10s, 30s, then 30s — **never gives up** |
| Session refresh | 60s before expiry, or on first 401 |
| Snackbar | 4s, 6s with action |
| In-app notification banner | 4s |
| Stale threshold | 120s (server-defined; do not hard-code) |
| Offline detection | 3 consecutive network failures |
| Cache TTL — checklist, incident types, evidence categories | 24h |
| Cache TTL — routes, stops | 7d |
| Manifest prefetch | at trip start, all stops |

---

**Next:** Phase 11 — Flutter implementation guide.
