# CTMS — Complete Application Blueprint

This is the **design-first blueprint** for the Campus Transport Management System: every
role, every screen, every workflow, every interaction, every rule. It is written so a
development team can build the entire product without coming back to ask questions.

It contains **no code, no database tables, no API definitions, no framework files**. Those
live elsewhere (`docs/05-database-design.md`, `docs/07-api-specification.md`). This document
is about *what the system is and how it behaves*.

---

## Documents

| # | Document | Phase | What it answers |
|---|----------|-------|-----------------|
| [01](01-system-analysis.md) | System Analysis | 1 | Who uses it, what they are responsible for, what the rules are, how modules depend on each other |
| [02](02-screens-conventions.md) | Screen Conventions | 2 | The rules every screen obeys, so individual screen specs stay about what is unique to them |
| [03](03-screens-shared-auth.md) | Shared & Auth Screens | 2 | Sign-in, onboarding, profile, notifications, settings — used by all roles |
| [04](04-screens-admin.md) | Admin & Staff Screens | 2 | The operations console — the largest surface in the product |
| [05](05-screens-driver.md) | Driver App Screens | 2 | The in-cab experience |
| [06](06-screens-student.md) | Student App Screens | 2 | The rider experience |
| [07](07-screens-parent.md) | Parent App Screens | 2 | The guardian experience |
| [08](08-functionality.md) | Functionality Catalogue | 3 | Every action, dialog, background process, status change, notification trigger and permission check |
| [09](09-system-flows.md) | System Flows | 4 | End-to-end flows with alternate paths and failure scenarios |
| [10](10-system-map.md) | System Map | 5 | Module hierarchy, navigation tree, screen transitions, dependency graph |

### Engineering governance

The five documents above define *what to build*. The five below define *how it gets built
consistently*, and how anyone can tell when a piece of it is actually finished.

| # | Document | What it answers |
|---|----------|-----------------|
| [11](11-ui-component-library.md) | UI Component Library | The 48 components every screen is assembled from. A screen may not invent one |
| [12](12-design-system.md) | Design System | Typography, colour, spacing, radius, elevation, motion, breakpoints, accessibility. A value that is not a token is a bug |
| [13](13-business-rule-catalogue.md) | Business Rule Catalogue | **The single source of truth for business rules.** 157 rules, each with its rationale, enforcement layer, error code and verifying test |
| [14](14-error-catalogue.md) | Error Catalogue | Every error the system can produce, with a stable code, user message and recovery path |
| [15](15-developer-handbook.md) | Developer Handbook | Folder structure, layering, naming, testing, git workflow, PR and review checklists |
| [16](16-module-completion-criteria.md) | Module Completion Criteria | The four completion levels and the checklist that makes "done" falsifiable |

**Precedence.** Where documents disagree, [13](13-business-rule-catalogue.md) wins on business
rules, [14](14-error-catalogue.md) wins on error behaviour, and
[12](12-design-system.md) wins on visual values. Screen specifications reference these; they
never restate them.

---

## How to read this blueprint

**Conventions are factored out, not omitted.** Every screen needs a loading state, an empty
state, an error state, pagination rules, and a permission check. Repeating the same
paragraph 170 times would bury the parts that actually differ. So
[02-screens-conventions.md](02-screens-conventions.md) states the defaults *once*, in full,
and each screen entry then documents only what is **specific or exceptional** for that
screen. A screen entry that does not mention its empty state is inheriting the standard one
described in the conventions document — that is a definition, not a gap.

**Every screen entry carries the same fields**, in this order:

- **Purpose** — the single job this screen does
- **Access** — which roles and permissions can open it
- **Entry points** — how a user arrives
- **Exit points** — where a user goes next
- **Actions** — everything the user can do here
- **Validations** — what is checked, and when
- **States** — empty / error / loading / success, where they differ from the defaults
- **Search, filter, sort, pagination** — where the screen lists data
- **Related workflows** — the flows in [09](09-system-flows.md) this screen participates in

**Requirement tags.** `FR-01`..`FR-15` refer to the functional requirements in
[`docs/00-srs.md`](../00-srs.md). Items marked **`[NEW]`** are capabilities this blueprint
identifies as necessary for a production system but which are **not** in the current SRS —
notably the entire Parent role, the finance/ticketing module, the gate/security module, and
substantial parts of the administrative back-office. They are called out so scope decisions
are explicit rather than accidental.

**Priority tags.** Each screen carries one:

- **`P0`** — the system cannot operate without it. A bus cannot run, or the law is broken.
- **`P1`** — the system operates but badly. Manual workarounds required daily.
- **`P2`** — quality, convenience, insight. Deferrable to a later release without harm.

---

## Scope summary

| Surface | Primary users | Screen count |
|---------|---------------|--------------|
| Admin & staff console (web) | Transport managers, operations staff, support desk, maintenance, finance, auditors | 111 |
| Driver app (mobile) | Drivers | 27 |
| Student app (mobile) | Students | 29 |
| Parent app (mobile) **`[NEW]`** | Parents and guardians | 22 |
| Shared / auth (all clients) | Everyone | 18 |
| **Total** | | **207** |

---

## Current build state

The blueprint is complete. The implementation is not, and
[16 §6](16-module-completion-criteria.md#6-current-state--honest-assessment) records exactly
where it stands rather than rounding up.

| | |
|---|---|
| Modules at L1 (internally verified) | 0, 1, 2, 3, 4A, 4B, 4C, 4D, 5, 6, 7 — **all** |
| Modules at L2 (integration verified) | 1, 2, 3 |
| Modules not started | none |
| Business rules verified by test | **142 of 157** (BR-354 backend half only) — the [15 that remain](#the-fifteen-unverified-rules) need domain that has no FR |
| Screens built | **0 of 207** |
| Notification platform | built — 12 triggers wired |
| Background jobs implemented | 10, on 9 schedules (BG-01, BG-02, BG-04..06, BG-08, BG-09, BG-11, BG-14, BG-16, BG-19, BG-20, escalation) |
| Google Maps | behind provider interfaces; offline fallback is the default binding and the one the suite runs on |
| Tests | **921 passing**, 3,607 assertions |

---

## The fifteen unverified rules

Recorded here rather than left as blank cells, because a blank cell reads as an
oversight and these are not.

**No FR to build against (13).** `BR-450`–`BR-457` describe a fees and payments
domain; `BR-015`, `BR-017`–`BR-020` describe multi-factor authentication,
guardian-link verification, impersonation and break-glass access. The
requirement set is `FR-01`..`FR-15` and none of them covers either area. There
is no parent/guardian role in the system — the roles are `ADMIN`, `DRIVER`,
`STUDENT` — so a guardian-link rule has nothing to link. Building these would
mean inventing requirements, which is a decision for the product owner rather
than something to slip in during a hardening pass.

**Partially enforced (2).**

| Rule | What is enforced | What is missing |
|---|---|---|
| `BR-500` | Record-level policies gate every student-owned resource; reports are aggregate only and are tested never to name a student | No test proves the *whole* surface is closed — that is an L3 claim needing the client |
| `BR-503` | The export path is role-gated and every use is logged with a reason | Field-level filtering is not implemented: an admin export returns every column the admin could see anyway, so nothing escapes today, but the rule as written is not satisfied |

---

## The one-paragraph version

A college runs a fleet of buses on fixed routes with fixed stops on a weekly timetable.
Every operating day the system turns that timetable into concrete trips, each with a bus, a
driver and a passenger list. Drivers run those trips from a phone, streaming position and
counting passengers on and off. Students and their parents watch the bus approach in real
time and get told when something changes. When a bus breaks, the system finds a replacement,
opens a maintenance job, and tells everyone affected. When buses run half-empty, it proposes
merging them. Everything anyone does to a bus, a driver, a student or a trip is written to an
audit trail, because when a child does not come home, somebody has to be able to reconstruct
exactly what happened.
