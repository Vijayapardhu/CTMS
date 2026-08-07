# Driver App — Phase 3: Screen Inventory

**Derived from:** [02 — User journeys](02-user-journeys.md)
**Rule:** nothing appears here that no journey reaches. If a screen has no journey, it has no reason to exist.

---

## Counting rule

A **screen** is a destination the router can push. A **surface** is something rendered inside one — a sheet, a dialog, a banner, a card. Most of what a driver interacts with is a surface, not a screen, and conflating the two is how apps end up with forty destinations and no coherent back stack.

| Type | Definition | Back behaviour |
|---|---|---|
| `ROOT` | Bottom-tab destination | Exits app |
| `PUSH` | Full screen on the stack | Pops |
| `MODAL` | Full screen, own stack, dismissible | Cancels the sub-flow |
| `SHEET` | Bottom sheet over the current screen | Dismisses |
| `DIALOG` | Blocking, requires a decision | Dismisses (unless critical) |
| `CHROME` | Persistent, not navigable | n/a |

**Total: 4 roots · 21 push · 4 modal · 7 sheet · 6 dialog · 5 chrome.**

---

## Roots

| ID | Screen | Journey | Notes |
|---|---|---|---|
| `R1` | **Trip** | J2, J3, J6, J7, J13 | Default tab. Changes shape by trip state; it is one destination, not four |
| `R2` | **Map** | J7, J8 | Full-screen live map. Separate so it can fill the screen during a run |
| `R3` | **Alerts** | J17 | Notifications + announcements. Badged |
| `R4` | **Me** | J1, J17 | Profile, duty status, queue, settings |

---

## Push screens

### Authentication
| ID | Screen | Journey | Entry | Exit |
|---|---|---|---|---|
| `P1` | Splash | J1 | Launch | Dashboard / Login / Session expired |
| `P2` | Login | J1 | Splash, logout, session expiry | Dashboard |
| `P3` | Forgot password | J1 | Login | Login (out-of-band reset; see note) |
| `P4` | Session expired | J1 | Any 401 after refresh fails | Login |

> **P3 note.** The frozen API exposes no password-reset endpoint — only `POST /auth/change-password` for an authenticated user. P3 therefore **cannot** send a reset email. It is an instruction screen: "Contact the transport office to reset your password", with a call action. Building a form that posts nowhere would be worse than being honest.

### Trip
| ID | Screen | Journey | Entry | Exit |
|---|---|---|---|---|
| `P5` | Trip details | J2 | Trip card, deep link | Back |
| `P6` | Vehicle blocked | J3, J14 | Readiness failed | Back, or call office |
| `P7` | Trip summary | J13 | Trip completed | Back to Trip / History |
| `P8` | Trip history | J2 | Me tab | Back |

### Inspection
| ID | Screen | Journey | Entry | Exit |
|---|---|---|---|---|
| `P9` | Inspection checklist | J4 | Readiness "Start inspection" | Submitted / discarded |
| `P10` | Inspection review | J4 | Checklist complete | Submitted |
| `P11` | Inspection result | J4 | Submission 201 | Trip |
| `P12` | Inspection history | J4 | Trip details | Back |

### Running trip
| ID | Screen | Journey | Entry | Exit |
|---|---|---|---|---|
| `P13` | Stop details | J8 | Geofence arrival, stop list | Back |
| `P14` | Student manifest | J8, J9 | Stop details | Back |
| `P15` | Boarding | J9 | Stop details, trip screen | Back |
| `P16` | ETA and delay | J7 | Trip screen, map | Back |

### Incidents
| ID | Screen | Journey | Entry | Exit |
|---|---|---|---|---|
| `P17` | SOS active | J10 | SOS confirmed | Cancelled / resolved by ops |
| `P18` | Incident report | J11, J12 | Trip actions | Submitted |
| `P19` | Incident detail | J10, J11 | Alerts, incident list | Back |
| `P20` | My incidents | J11 | Me tab | Back |
| `P21` | Replacement status | J12 | Push, incident detail | Back |

---

## Modals

| ID | Modal | Journey | Why modal |
|---|---|---|---|
| `M1` | **Evidence capture** | J5 | Sub-flow of inspection *and* incident. Owns its stack so cancelling returns to the parent cleanly |
| `M2` | **Evidence preview** | J5 | Second step of M1 |
| `M3` | **Offline queue** | J15, J16 | Reached from banner or Me; not part of any task flow |
| `M4` | **Permission request** | J17 | Interrupts whatever asked for it |

---

## Sheets

| ID | Sheet | Journey | Trigger |
|---|---|---|---|
| `S1` | SOS confirm | J10 | Hold SOS |
| `S2` | Start trip confirm | J6 | Tap Start |
| `S3` | End trip confirm | J13 | Tap End — includes odometer entry |
| `S4` | Skip stop | J8 | Skip — reason required |
| `S5` | Left behind | J8 | From manifest — student multi-select |
| `S6` | Incident type picker | J11 | Report incident |
| `S7` | Duty status | J17 | Me tab |

---

## Dialogs

| ID | Dialog | Journey | Dismissible |
|---|---|---|---|
| `D1` | Discard inspection? | J4 | Yes |
| `D2` | Cancel SOS | J10 | Yes — note required to proceed |
| `D3` | Vehicle will be grounded | J4 | Yes — shown *before* submitting a failing inspection |
| `D4` | Trip already closed | J13 | Yes |
| `D5` | Logout | J17 | Yes |
| `D6` | Force update | — | **No** — blocks until updated |

---

## Chrome

Persistent surfaces. None is navigable; all can appear over any screen.

| ID | Element | Journey | Visible when |
|---|---|---|---|
| `C1` | **SOS control** | J10 | Trip is RUNNING — every screen, above the tab bar |
| `C2` | Offline banner | J15 | No connectivity |
| `C3` | Sync banner | J15 | Queue non-empty and syncing |
| `C4` | GPS status pill | J7 | Trip RUNNING |
| `C5` | Stale position badge | J7 | `is_stale = true` |

---

## Error and system screens

Distinguished from *empty* states, which belong to their parent screen.

| ID | Screen | Type | Journey | Recovery |
|---|---|---|---|---|
| `E1` | No internet | CHROME | J15 | Automatic on restore |
| `E2` | GPS disabled | PUSH | J7, J17 | Open system settings |
| `E3` | Location permission | MODAL | J17 | Grant, or settings |
| `E4` | Camera permission | MODAL | J17, J5 | Grant, or settings |
| `E5` | Notification permission | MODAL | J17 | Grant, or dismiss (degraded) |
| `E6` | Forbidden (403) | PUSH | any | Back — never retry |
| `E7` | Not found (404) | — | any | **Not a screen.** Renders as the parent's empty state |
| `E8` | Server error (500) | PUSH | any | Retry |
| `E9` | Maintenance mode (503) | PUSH | any | Poll, auto-recover |

---

## Deliberately absent

Recording these prevents them being "discovered" as gaps later. Each is absent because a journey proves it should be.

| Not built | Reason |
|---|---|
| Password reset form | No endpoint exists. P3 is an instruction screen instead |
| Registration / sign-up | Self-registration creates STUDENT accounts only. A driver account is created by operations |
| Replacement approve / reject | `403` for a driver (BR-359). P21 is status-only |
| Incident acknowledge / resolve / close | `403` — triage is an operations decision |
| Maintenance sign-off | `403` — that act returns a vehicle to the road (BR-358) |
| Student profile | `403` always. The driver gets the stop manifest (P14) instead |
| Trip correction | `403` — corrections to a closed trip are operations-only (BR-258) |
| Route editor | `403` |
| Bus assignment | `403` |
| Notification composer | Drivers read announcements; they do not write them |
| Standalone "Diversion" screen | `DIVERSION` is an incident type. P18 covers it |
| Standalone settings for map layers | Not enough map configuration to warrant a screen |

---

## Screen → journey coverage

Every journey reaches at least one screen; every screen is reached by at least one journey.

| Journey | Screens |
|---|---|
| J1 Authentication | P1 P2 P3 P4 |
| J2 Today's trip | R1 P5 P7 |
| J3 Readiness | R1 P6 |
| J4 Inspection | P9 P10 P11 P12 D1 D3 |
| J5 Evidence | M1 M2 E4 |
| J6 Start trip | R1 S2 |
| J7 Running | R1 R2 P16 C1 C4 C5 E2 |
| J8 Stop arrival | P13 P14 S4 S5 |
| J9 Boarding | P15 P14 |
| J10 SOS | C1 S1 P17 D2 |
| J11 Breakdown | S6 P18 P19 P20 M1 |
| J12 Replacement | P21 P19 |
| J13 End trip | S3 P7 D4 |
| J14 Vehicle blocked | P6 |
| J15 Offline | C2 C3 M3 E1 |
| J16 Sync conflicts | M3 |
| J17 Permissions | M4 E2 E3 E4 E5 R4 S7 |

**Next:** Phase 4 — State machines for each of these.
