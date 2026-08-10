# Icon registry

Hugeicons, Stroke Rounded only — the same rule the driver app follows. Mixing
weights inside one product looks like two products.

## The rule

No component references a Hugeicons symbol directly. Every icon goes through a
registry with a semantic name, exactly as `lib/core/icons/app_icons.dart` does
it in the driver app. A screen asks for `AppIcon.incident`, never for
`Bus01`. When a symbol turns out not to exist, one file changes.

Each entry carries a **verified** Hugeicons symbol and a Material fallback. The
fallback is not decoration: it is what renders if the symbol is missing from
the installed package version, and the registry integrity test asserts every
entry has one.

## Verified — inherited from the driver app

These symbols are already rendering in a shipped Flutter build, so they exist.
The web package (`hugeicons-react` / `@hugeicons/core-free-icons`) uses the same
names in PascalCase without the `strokeRounded` prefix.

| Semantic | Hugeicons (Flutter) | Web name | Meaning |
|---|---|---|---|
| `bus` | `strokeRoundedBus01` | `Bus01Icon` | A vehicle |
| `map` | `strokeRoundedNavigation03` | `Navigation03Icon` | Live operations |
| `alerts` | `strokeRoundedNotification03` | `Notification03Icon` | Notification |
| `user` | `strokeRoundedUser` | `UserIcon` | One person |
| `people` | `strokeRoundedUserGroup` | `UserGroupIcon` | Students, passengers |
| `settings` | `strokeRoundedSettings01` | `Settings01Icon` | Administration |
| `back` | `strokeRoundedArrowLeft01` | `ArrowLeft01Icon` | Back |
| `close` | `strokeRoundedCancel01` | `Cancel01Icon` | Dismiss |
| `chevron` | `strokeRoundedArrowRight01` | `ArrowRight01Icon` | Drill in |
| `tripStart` | `strokeRoundedPlayCircle` | `PlayCircleIcon` | Running |
| `tripEnd` | `strokeRoundedStopCircle` | `StopCircleIcon` | Completed |
| `route` | `strokeRoundedRoute01` | `Route01Icon` | A route |
| `stop` | `strokeRoundedLocation01` | `Location01Icon` | A stop |
| `destination` | `strokeRoundedFlag02` | `Flag02Icon` | Final stop |
| `eta` | `strokeRoundedTimer02` | `Timer02Icon` | Estimate |
| `schedule` | `strokeRoundedCalendar03` | `Calendar03Icon` | Timetable, date range |
| `gpsLive` | `strokeRoundedGps01` | `Gps01Icon` | Fresh position |
| `gpsStale` | `strokeRoundedGps02` | `Gps02Icon` | Ageing position |
| `gpsOff` | `strokeRoundedGpsOff01` | `GpsOff01Icon` | No position |
| `offline` | `strokeRoundedCloudOff` | `CloudOffIcon` | API unreachable |
| `online` | `strokeRoundedWifi01` | `Wifi01Icon` | Reachable |
| `refresh` | `strokeRoundedRefresh` | `RefreshIcon` | Reload, resend |
| `checklist` | `strokeRoundedCheckList` | `CheckListIcon` | Inspection |
| `pass` | `strokeRoundedCheckmarkCircle02` | `CheckmarkCircle02Icon` | Passed |
| `fail` | `strokeRoundedCancelCircle` | `CancelCircleIcon` | Failed |
| `safetyCritical` | `strokeRoundedAlert02` | `Alert02Icon` | Critical item |
| `evidence` | `strokeRoundedImage01` | `Image01Icon` | Photograph |
| `odometer` | `strokeRoundedDashboardSpeed01` | `DashboardSpeed01Icon` | Mileage |
| `sos` | `strokeRoundedShieldEnergy` | `ShieldEnergyIcon` | SOS incident |
| `breakdown` | `strokeRoundedAlert01` | `Alert01Icon` | Breakdown |
| `accident` | `strokeRoundedAlertCircle` | `AlertCircleIcon` | Accident |
| `maintenance` | *(driver registry)* | `Wrench01Icon` | Workshop |
| `capacity` | `strokeRoundedChartBarLine` | `ChartBarLineIcon` | Occupancy |
| `document` | *(driver registry)* | `File01Icon` | Statutory document |
| `history` | *(driver registry)* | `Clock01Icon` | Timeline |
| `success` `warning` `error` `info` `pending` `blocked` | *(driver registry)* | as driver app | Status set |

## Panel-only — NOT yet verified

The panel needs concepts the driver app never had. **Every one of these must be
checked against the installed package before implementation**, and each carries
a Material fallback that is known to exist.

| Semantic | Proposed Hugeicons | Verified? | Fallback | Meaning |
|---|---|---|---|---|
| `dashboard` | `Dashboard01Icon` | **?** | `Icons.dashboard_rounded` | Dashboard |
| `search` | `Search01Icon` | **?** | `Icons.search_rounded` | Table search |
| `filter` | `FilterIcon` | **?** | `Icons.filter_alt_rounded` | Filter bar |
| `sort` | `SortingAZ01Icon` | **?** | `Icons.swap_vert_rounded` | Column sort |
| `download` | `Download01Icon` | **?** | `Icons.download_rounded` | CSV |
| `report` | `ChartLineData01Icon` | **?** | `Icons.insert_chart_rounded` | Reports |
| `audit` | `SecurityCheckIcon` | **?** | `Icons.fact_check_rounded` | Audit log |
| `accessLog` | `EyeIcon` | **?** | `Icons.visibility_rounded` | Data access |
| `announcement` | `Megaphone01Icon` | **?** | `Icons.campaign_rounded` | Announcement |
| `send` | `SentIcon` | **?** | `Icons.send_rounded` | Publish, resend |
| `assign` | `UserAdd01Icon` | **?** | `Icons.person_add_rounded` | Assign driver / mechanic |
| `swap` | `ArrowDataTransferHorizontalIcon` | **?** | `Icons.swap_horiz_rounded` | Reassign, replacement |
| `more` | `MoreVerticalIcon` | **?** | `Icons.more_vert_rounded` | Row action menu |
| `expand` | `ArrowDown01Icon` | **?** | `Icons.expand_more_rounded` | Disclosure |
| `pin` | `MapsLocation01Icon` | **?** | `Icons.place_rounded` | Map marker |
| `fitBounds` | `FullScreenIcon` | **?** | `Icons.fit_screen_rounded` | Fit route |
| `close` (drawer) | reuse `Cancel01Icon` | ✓ | — | Close drawer |

**Rule for implementation.** A `?` may not reach a merged branch. Either the
symbol is verified and the `?` removed, or the fallback becomes the entry. The
registry integrity test — the same idea as the driver app's — asserts that no
entry is left unresolved.

## Icon usage rules

1. **Never colour alone.** Every semantic colour is paired with an icon, for
   the same reason as the driver app: about eight percent of male staff have a
   colour vision deficiency, and a red dot on its own says nothing to them.
2. One icon per meaning. `Bus01Icon` is a vehicle everywhere — never a route,
   never a trip.
3. Sizes are the design system's, not per-screen: `xs 16` in chips and table
   cells, `sm 20` in list rows and buttons, `md 24` in navigation, `lg 28` on
   dashboard metric cards.
4. Decorative icons are `aria-hidden`. An icon that carries meaning gets a
   label, and an icon-only button always gets one.
