# CTMS Admin / Transport Operations Panel — specification

**Status: DRAFT, pending freeze.** Nothing here has been implemented. No panel
code exists and no backend file was changed in producing it.

This specification was written by reading the backend, not by designing a
dashboard and hoping the API would cooperate. Every endpoint named in these
documents was taken from `php artisan route:list` against the frozen backend —
158 API routes — and every access level was read from the route middleware and
the policies. Where a screen wants something the backend does not provide, it
is recorded in `00-backend-gaps.md` and nowhere else. There are no invented
endpoints in these documents.

## Read in this order

| # | Document | What it settles |
|---|---|---|
| 00 | `00-backend-gaps.md` | **Read first.** What the backend cannot do, and what that costs |
| 01 | `01-api-contract.md` | Every endpoint the panel uses, with status-code handling |
| 02 | `02-screen-api-matrix.md` | Screen → endpoint, both directions, with an unused-endpoint audit |
| 03 | `03-user-journeys.md` | J1–J17, the operational stories the panel exists to serve |
| 04 | `04-state-machines.md` | The nine workflows, their transitions and their failures |
| 05 | `05-wireframes.md` | Structural layout only, no colour |
| 06 | `06-component-library.md` | The components, their variants and states |
| 07 | `07-design-system.md` | Tokens, inherited from the driver app |
| 08 | `08-icon-registry.md` | Hugeicons symbols, verified against the driver app registry |
| 09 | `09-screen-specifications.md` | A1–A16 in full |
| 10 | `10-interaction-specifications.md` | Drawers, tables, confirmation, feedback |
| 11 | `11-implementation-guide.md` | Stack, architecture, build slices |
| 12 | `12-access-control-matrix.md` | Capability × access level, generated from the router |

## Operational RBAC — phase 0

Added when the panel stopped being a prototype and became the operational
control surface. Read `rbac-audit.md` first: it contains a proven backend
defect (G3-3) that the workflow phases depend on.

| Document | What it settles |
|---|---|
| `rbac-audit.md` | The audit, the capability map, the gaps, the phases |
| `rbac-model.md` | How authorisation works and what the panel may do about it |
| `capability-matrix.md` | Every capability id, endpoint and enforced tier |
| `operational-workflows.md` | The ten workflows, their real states and transitions |
| `authorization-states.md` | Screen and action states, and what each one says |
| `capability-map.json` | Generated from the router. Never edited |

## What this panel is

A desktop-first operations console for the college transport office, run by
staff who already have `UserRole::ADMIN` and one of four access levels. It is
the other half of the system the driver app is the first half of: the driver
reports, the office watches and decides.

It is being built as a **working prototype for college management**, against
real data from the frozen backend. It is not a demo with fixed numbers in it.
Nothing in these documents describes fabricated data, and no screen is
specified that cannot be filled from a real endpoint.

## What it is not

Not a mobile app. Not a rebuild of the driver app for a bigger screen. The
driver holds a phone in a cradle and needs one decision at a time; the
transport head sits at a laptop and needs twenty rows at once. The two share a
backend, a vocabulary and a colour palette, and share almost no layout.

## Vocabulary

Terminology is the backend's, and the driver app's. Where the two already agree
on a word, this specification does not invent a third one.

| Term | Means, precisely |
|---|---|
| Trip | One scheduled run of one bus over one route on one date |
| Running | `TripStatus::RUNNING` — started, not yet completed |
| Live | A position the server has not marked `is_stale` |
| Stale | `is_stale: true` from the server. **Never recomputed on the client** |
| Service readiness | `GET /buses/{id}/service-readiness` — `cleared` plus `reasons[]` |
| Road distance | `distance_metres` from the ETA endpoint. Never a straight line |
| Estimate | `distance_is_estimate: true` — the routing provider could not answer |
| Access level | `AccessLevel` on the admin profile. Not a role |
| Incident | `VehicleIncident`. SOS is an incident *type*, not a separate thing |

## Conventions in these documents

- Screens are `A1`…`A16`. Drawers are `D1`…, dialogs `M1`…
- Endpoints are written as the router has them, without the `/api/v1` prefix
- `BACKEND GAP` marks something the backend does not support today
- Access levels are `VIEWER < SUPPORT < OPERATIONS < SUPER_ADMIN`, compared
  with `atLeast()`, exactly as `RequireAccessLevel` does it
