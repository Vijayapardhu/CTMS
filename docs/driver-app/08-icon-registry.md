# Driver App — Phase 8: Icon Registry

**Derived from:** [06 — Component library](06-component-library.md), [07 — Design system](07-design-system.md)
**Library:** Hugeicons · **Style:** Stroke Rounded · **Default:** 24dp

---

## The verification problem, stated plainly

I have not been able to run `flutter pub add hugeicons` and enumerate the package's symbols. Several names in circulation for this project — `Bus Swap`, `Engine`, `Wheel`, `GPS Slash` — I **cannot confirm exist**.

Writing them into a spec would produce a compile error on day one and a developer guessing at substitutes with no rule for choosing.

So this registry is built on a pattern rather than a list: **every icon is declared once, with a verified Material fallback**. If a Hugeicon name is wrong or renamed, one line changes and nothing else in the app knows.

---

## The registry pattern

```dart
/// Every icon in the app is declared here and nowhere else.
///
/// A widget never references `HugeIcons.*` or `Icons.*` directly. When an icon
/// name turns out to be wrong — or Hugeicons renames one in a minor release —
/// exactly one line in this file changes and no screen is touched.
///
/// `fallback` is a Material symbol verified to exist. It is not decoration: it
/// is what renders if the preferred symbol resolves to null, which is what
/// keeps a wrong name from becoming a blank square on a driver's screen.
class AppIcon {
  const AppIcon._(this.preferred, this.fallback, {this.semanticLabel});

  final IconData? preferred;
  final IconData fallback;
  final String? semanticLabel;

  IconData get data => preferred ?? fallback;

  // ── Navigation ───────────────────────────────────────────────
  static const trip = AppIcon._(
    HugeIcons.strokeRoundedBus01, Icons.directions_bus,
    semanticLabel: 'Trip');
  static const map = AppIcon._(
    HugeIcons.strokeRoundedNavigation03, Icons.navigation,
    semanticLabel: 'Map');
  static const alerts = AppIcon._(
    HugeIcons.strokeRoundedNotification03, Icons.notifications,
    semanticLabel: 'Alerts');
  static const profile = AppIcon._(
    HugeIcons.strokeRoundedUser, Icons.person,
    semanticLabel: 'Me');
  // …
}
```

Rendered through one widget, never `Icon()` directly:

```dart
class AppIconView extends StatelessWidget {
  const AppIconView(this.icon, {this.size = 24, this.color, super.key});
  final AppIcon icon;
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) => Icon(
        icon.data,
        size: size,
        color: color ?? IconTheme.of(context).color,
        semanticLabel: icon.semanticLabel,
      );
}
```

### Day-one task

Before any screen is built, someone installs Hugeicons and runs through this registry confirming each `preferred` symbol resolves. Every name below is marked:

- **`✓`** — a name I am confident maps to a real Hugeicons symbol
- **`?`** — plausible, **must be verified**; the fallback is correct regardless

---

## Registry

### Navigation

| Key | Hugeicons (Stroke Rounded) | | Material fallback | Size |
|---|---|---|---|---|
| `trip` | `Bus01` | ✓ | `directions_bus` | 24 |
| `map` | `Navigation03` | ? | `navigation` | 24 |
| `alerts` | `Notification03` | ? | `notifications` | 24 |
| `profile` | `User` | ✓ | `person` | 24 |
| `settings` | `Settings01` | ✓ | `settings` | 24 |
| `back` | `ArrowLeft01` | ✓ | `arrow_back` | 24 |
| `close` | `Cancel01` | ✓ | `close` | 24 |
| `chevron` | `ArrowRight01` | ✓ | `chevron_right` | 20 |

### Trip lifecycle

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `tripStart` | `PlayCircle` | ✓ | `play_circle` | 28 |
| `tripEnd` | `StopCircle` | ? | `stop_circle` | 28 |
| `tripRunning` | `Bus01` | ✓ | `directions_bus` | 28 |
| `route` | `Route01` | ? | `route` | 24 |
| `stop` | `Location01` | ✓ | `place` | 24 |
| `destination` | `Flag02` | ? | `flag` | 24 |
| `eta` | `Timer02` | ? | `timer` | 20 |
| `distance` | `RoadLocation01` | ? | `straighten` | 20 |
| `schedule` | `Calendar03` | ✓ | `calendar_today` | 20 |

### GPS and connectivity

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `gpsLive` | `Gps01` | ? | `gps_fixed` | 20 |
| `gpsAcquiring` | `Gps02` | ? | `gps_not_fixed` | 20 |
| `gpsOff` | `GpsOff01` | ? | `gps_off` | 20 |
| `offline` | `CloudOfflineSlash` | ? | `cloud_off` | 20 |
| `online` | `Wifi01` | ✓ | `wifi` | 20 |
| `sync` | `RefreshCircle` | ? | `sync` | 20 |
| `signalWeak` | `SignalLow` | ? | `signal_cellular_alt_1_bar` | 16 |

> Every GPS symbol is `?`. If **any** fails to resolve, use the Material set wholesale for this group rather than mixing — a half-swapped group is visually worse than a consistent fallback.

### Boarding

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `board` | `ArrowRight01` | ✓ | `arrow_forward` | 32 |
| `alight` | `ArrowLeft01` | ✓ | `arrow_back` | 32 |
| `student` | `User` | ✓ | `person` | 24 |
| `passengers` | `UserGroup` | ✓ | `groups` | 28 |
| `capacity` | `ChartBarLine` | ? | `bar_chart` | 20 |
| `leftBehind` | `UserRemove01` | ? | `person_remove` | 24 |

### Inspection

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `checklist` | `CheckList` | ? | `checklist` | 24 |
| `pass` | `CheckmarkCircle02` | ✓ | `check_circle` | 24 |
| `fail` | `CancelCircle` | ? | `cancel` | 24 |
| `safetyCritical` | `Alert02` | ? | `warning` | 16 |
| `camera` | `Camera01` | ✓ | `photo_camera` | 24 |
| `upload` | `Upload01` | ? | `upload` | 20 |
| `evidence` | `Image01` | ✓ | `image` | 24 |
| `odometer` | `DashboardSpeed01` | ? | `speed` | 20 |

### Incidents

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `sos` | `ShieldEnergy` | ? | `emergency` | **36** |
| `breakdown` | `Alert01` | ? | `warning_amber` | 28 |
| `accident` | `AlertCircle` | ? | `error` | 28 |
| `medical` | `MedicalMask` | ? | `medical_services` | 28 |
| `diversion` | `RouteBlock` | ? | `alt_route` | 24 |
| `replacement` | `Exchange01` | ? | `swap_horiz` | 24 |
| `maintenance` | `Wrench01` | ✓ | `build` | 24 |
| `fuel` | `FuelStation01` | ? | `local_gas_station` | 24 |
| `tyre` | `Tire` | ? | `tire_repair` | 24 |

> `sos` is the one icon where a wrong glyph is a safety issue. If `ShieldEnergy` does not resolve, use Material `emergency` — do not substitute another Hugeicon without looking at it. It must read as *emergency*, not *warning*.

### Status and feedback

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `success` | `CheckmarkCircle02` | ✓ | `check_circle` | 20 |
| `warning` | `Alert02` | ? | `warning` | 20 |
| `error` | `AlertCircle` | ? | `error` | 20 |
| `info` | `InformationCircle` | ✓ | `info` | 20 |
| `pending` | `Clock01` | ✓ | `schedule` | 16 |
| `blocked` | `MinusSignCircle` | ? | `do_not_disturb_on` | 20 |

### Actions

| Key | Hugeicons | | Fallback | Size |
|---|---|---|---|---|
| `call` | `Call02` | ✓ | `call` | 24 |
| `sms` | `Message01` | ✓ | `sms` | 24 |
| `retry` | `RefreshCircle` | ? | `refresh` | 20 |
| `history` | `Clock04` | ? | `history` | 24 |
| `document` | `File01` | ✓ | `description` | 24 |
| `help` | `HelpCircle` | ✓ | `help` | 24 |
| `logout` | `Logout03` | ? | `logout` | 24 |

---

## Sizes in use

Not the nine-value table from the brief — the six that components actually use.

| Size | Where |
|---|---|
| 16 | Inside chips, inline markers |
| 20 | Status icons, list leading, pills |
| 24 | **Default.** Navigation, buttons, list items |
| 28 | Dashboard cards, incident types |
| 32 | Counter buttons |
| 36 | SOS only |

`18` and `22` from the brief are omitted. Nothing needed them, and two near-identical sizes in a system get used interchangeably.

---

## Colour rules

An icon is **never** coloured decoratively.

| Context | Colour |
|---|---|
| Navigation, inactive | `onSurfaceVariant` |
| Navigation, active | `primary` |
| Inside a filled button | `onPrimary` |
| Status icon | its semantic token (`positive` / `caution` / `critical` / `neutral` / `info`) |
| Disabled | `disabled` |
| SOS | `emergency` |
| Map overlay | `liveAccent` or `staleAccent` |

**Every semantic colour requires its paired icon.** From Phase 7: colour never carries meaning alone.

---

## Style discipline

- **One style: Stroke Rounded.** Never Solid, Bulk, Duotone or Twotone anywhere.
- Fallbacks use Material **Rounded**, which is the closest match to Hugeicons Stroke Rounded.
- Stroke weight: package default (medium). Do not override per-icon.

## Animation

Only where it carries meaning (Phase 7 durations):

| Icon | Motion |
|---|---|
| `gpsAcquiring` | 1600 ms opacity pulse |
| `sync` | 1000 ms linear rotation |
| `sos` (active) | 2000 ms subtle pulse |
| `success` | 400 ms stroke draw, once |
| `error` | 300 ms shake, twice |

Nothing else animates.

---

## Accessibility

- Every `AppIcon` carries a `semanticLabel`. An icon-only button without one is a bug.
- Purely decorative icons — the empty-state illustration is the only case — set `ExcludeSemantics`.
- No icon is the sole carrier of meaning: chips pair icon with text, the GPS pill pairs icon with a word.

**Next:** Phase 9 — Screen specifications.
