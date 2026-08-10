# Implementation guide

Recommended after inspecting the repository. **No panel code exists yet** and
none should be written until this specification is frozen.

## What is already in the repository

- `backend/` — Laravel 12 / PHP 8.5, 158 API routes, 1036 tests, frozen
- `driver_app/` — Flutter, 441 tests, implemented against the frozen contract
- `docs/driver-app/` — the frozen driver specification, including
  `openapi-full.json` (158 operations, 122 paths) and `openapi-driver.json`
- `backend/package.json` — Vite 6 and Tailwind 4 **already present**, from
  Laravel's default scaffolding. Unused by anything today

There is no admin frontend of any kind. Nothing is being replaced.

## Stack

| Choice | Why this one |
|---|---|
| **React 19 + TypeScript** | The panel is tables, drawers and one map. Types matter because 158 endpoints is more contract than a person can hold |
| **Vite 6** | Already the repo's bundler. No new toolchain |
| **Tailwind 4** | Already present. Design tokens from `07` become CSS variables; no component framework to fight |
| **TanStack Query** | Every screen is server state with polling, staleness, retry and invalidation. Writing that by hand is how the polling budget in G2-1 gets quietly broken |
| **TanStack Table** | Headless. The table is the panel; `C8` needs sorting, column priority and keyboard rows without inheriting someone's visual opinions |
| **React Router** | Filters, tabs, pagination and open drawers all live in the URL (`10`) |
| **`@vis.gl/react-google-maps`** | Google's own React wrapper for the Maps JS API |
| **`openapi-typescript` + `openapi-fetch`** | **Types generated from `docs/driver-app/openapi-full.json`.** Backend models are never hand-copied |
| **Vitest + Testing Library + MSW** | MSW mirrors the driver app's `FakeBackend`: tests exercise the real client against scripted HTTP |

Deliberately **not** chosen: Next.js (no SSR need, and a Node server to deploy),
Redux (server state is not client state), a component kit like MUI or AntD (they
bring their own design system, and this one already exists), WebSockets
(see `21` — bounded polling first).

## Where it lives

```text
admin_panel/                 top level, beside driver_app/
├── src/
│   ├── api/                 generated types + typed client, one file per domain
│   ├── auth/                session, token refresh, can()
│   ├── components/          C1–C26
│   ├── features/            one folder per screen area
│   ├── design/              tokens, theme
│   └── routes/
├── docs/                    panel-specific setup notes
└── tests/
```

Top level, not `backend/resources`, because it deploys separately, has its own
lifecycle and matches how `driver_app/` already sits in this repo.

## Contract, not duplication

```bash
npx openapi-typescript ../docs/driver-app/openapi-full.json -o src/api/schema.d.ts
```

Committed and regenerated when the backend changes. The generated document uses
a generic `Envelope` schema per response, so **response bodies are typed by
hand once, per domain, in `src/api/*.ts`** — from the shapes recorded in
`01-api-contract.md`, not from guesswork. Paths, methods and parameters come
from the generated types, which is where drift actually bites.

## Configuration

Build-time, via Vite env. Nothing secret is compiled in.

```text
VITE_CTMS_API_BASE_URL       e.g. https://<server>/api/v1
VITE_GOOGLE_MAPS_BROWSER_KEY  HTTP-referrer-restricted browser key
```

Naming deliberately parallels the driver app's `CTMS_API_BASE_URL`.

## Google Maps

**Only one Google API is needed in the browser: Maps JavaScript API.**

Not needed, and not to be enabled on the browser key: Routes, Route Matrix,
Geocoding, Places, Roads. All five are server-side and already integrated —
`GoogleRoutingProvider` returns `distance_metres`, `duration` and an encoded
polyline, and the panel consumes those through `/trips/{id}/eta`. The browser
computes no distance and requests no route.

**Key restrictions, required:**

- Application restriction: **HTTP referrers**, the panel's origin only
- API restriction: **Maps JavaScript API** only
- A separate key from the Android key and from the server key. Three keys, one
  project, three restrictions

The panel must render usefully with **no key at all**: the map area shows
"Map not configured" and every list, table and drawer keeps working. A missing
key is a deployment state, not a broken screen.

## Session

- Access + refresh tokens in memory, refresh persisted to `sessionStorage`
- A 401 triggers **one** refresh, single-flight across concurrent requests, then
  a retry. A second failure ends the session
- `GET /auth/me` on boot, before the first screen renders: it carries the
  access level everything gates on
- **A non-ADMIN token is signed out immediately** with "This panel is for
  transport office staff."
- No offline session. Unlike the driver app there is nothing useful to do here
  without the API

## Security rules

1. No privilege decision in the frontend. `can()` decides what to *offer*; the
   server decides what happens
2. No secrets in source. Two build-time env values, both non-secret by design
3. No personal data in a URL. Ids only, never names or roll numbers
4. Evidence is fetched by id through the API and never guessed from a storage
   path
5. A 403 renders a fixed sentence — never the server's internal message, never
   the endpoint
6. Destructive actions confirm, and the confirmation names the consequence
7. The eight endpoints in G3-1 are hidden by level in the UI **and recorded as
   not server-enforced**, so nobody mistakes the hiding for a control

## Build slices

Each slice ends with the panel working and demonstrable. The gate for every
slice: typecheck clean, tests pass, build succeeds, tree clean.

| Slice | Contents | Demonstrable at the end |
|---|---|---|
| **0** | Vite + TS + Tailwind, tokens from `07`, icon registry from `08`, generated API types, MSW harness, C1–C4 shell | An empty console with real navigation |
| **1** | Login, session, refresh, `auth/me`, `can()`, access-level gating, 401/403 handling | Sign in as each of four levels and see the navigation change |
| **2** | A1 Dashboard, C5, C20–C22, the six-request loading strategy | **Real fleet numbers from the real backend** |
| **3** | A3 Trips, A4 Trip Details, C8, C9, C11, C16, C23 | Today's trips, filtered, with detail |
| **4** | A2 Live Operations, C12–C15, bounded polling, D1 | **A bus on a Google map with a real road distance and ETA** |
| **5** | A5 Fleet, A6 Bus Details, readiness, documents | The fleet, and why a bus is not cleared |
| **6** | A8, A9, A10 — incidents, evidence viewer, maintenance, replacement | **SOS → acknowledge → resolve, and breakdown → replacement** |
| **7** | A11 Inspections, A7 Drivers, A12 Students | Failed inspection → ticket, and the people views |
| **8** | A15 Reports, A16 Audit, A13, A14 | Reports, and who did what |
| **9** | Polish: empty/error sweep, dark theme pass, keyboard, responsive, walkthrough | Demo-ready |

**The first genuinely useful milestone is the end of slice 4**, which is
exactly the demo story: login → dashboard → today's trips → live bus on a
Google map. Slices 0–4 are the priority; 5–9 make it a product.

## MVP classification

| Feature | Class |
|---|---|
| A1, A2, A3, A4, A5, A6, A8, A9, A10 | **MVP** |
| A7, A11, A12, A15, A16 | **MVP** |
| A13, A14 | MVP if cheap |
| A17 Routes read-only, A18 Accounts | MVP if cheap |
| Bus/driver/student CRUD writes | MVP if cheap |
| Route, schedule, service-calendar writes | Post-MVP |
| Consolidations, attendance disputes | Post-MVP — and both carry G3-1 |
| Server-side export, saved report views | Post-MVP |
| WebSockets / live push | Backend future — bounded polling first |
| `/fleet/live`, `/inspections` index, dashboard aggregation | Backend future — G2-1, G2-2, G1-1 |
| Role management UI, multi-depot, analytics, telemetry | **Out of scope** |

## Testing

Match the driver app's discipline, not its volume.

- MSW scripts the real HTTP; components use the real client
- Per screen: loads, empty, error, forbidden, and one mutation refused with the
  server's words shown verbatim
- One test per access level asserting the navigation and actions each sees
- The polling budget is a test: A2 must issue at most `1 + 12 + 1` requests per
  cycle. That number is a promise to the backend and should fail loudly
- No enterprise load testing, no visual regression suite in the MVP
